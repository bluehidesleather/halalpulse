<?php

declare(strict_types=1);

namespace HalalPulse\Documents;

use HalalPulse\Support\JsonLogger;
use RuntimeException;
use Throwable;

final class DocumentPipeline
{
    public function __construct(
        private readonly DocumentStore $store,
        private readonly OfficialDocumentDownloader $downloader,
        private readonly PdfTextExtractor $textExtractor,
        private readonly MetricCandidateExtractor $metricExtractor,
        private readonly JsonLogger $logger,
        private readonly string $storageRoot,
    ) {
    }

    /** @return array{status: string, recovered: int, seeded: int, downloaded: int, unsupported: int, download_failed: int, extracted: int, manual_review: int, extraction_failed: int, metric_candidates: int} */
    public function run(int $batchSize): array
    {
        $counts = [
            'status' => 'succeeded',
            'recovered' => 0,
            'seeded' => 0,
            'downloaded' => 0,
            'unsupported' => 0,
            'download_failed' => 0,
            'extracted' => 0,
            'manual_review' => 0,
            'extraction_failed' => 0,
            'metric_candidates' => 0,
        ];

        if (!$this->store->acquireLock()) {
            $counts['status'] = 'skipped';
            return $counts;
        }

        try {
            $counts['recovered'] = $this->store->recoverStaleDownloads();
            $counts['seeded'] = $this->store->seedPending();

            foreach ($this->store->nextDownloads($batchSize) as $item) {
                $this->store->markDownloading($item->documentId);

                try {
                    $file = $this->downloader->download($item);
                    $this->store->markDownloaded($item->documentId, $file);
                    $counts['downloaded']++;
                    $this->logger->info('Official filing document downloaded.', [
                        'document_id' => $item->documentId,
                        'filing_id' => $item->filingId,
                        'bytes' => $file->sizeBytes,
                        'sha256_prefix' => substr($file->sha256, 0, 16),
                    ]);
                } catch (UnsupportedDocumentException $exception) {
                    $this->store->markUnsupported($item->documentId, $exception->getMessage());
                    $counts['unsupported']++;
                } catch (Throwable $exception) {
                    $this->store->markDownloadFailure($item->documentId, $exception->getMessage());
                    $counts['download_failed']++;
                    $this->logger->error('Official filing document download failed.', [
                        'document_id' => $item->documentId,
                        'exception' => $exception::class,
                        'message' => $exception->getMessage(),
                    ]);
                }
            }

            foreach ($this->store->nextExtractions($batchSize) as $item) {
                if (!$this->textExtractor->available()) {
                    $this->store->markManualReview(
                        $item->documentId,
                        'Automated PDF text extraction is unavailable on this host.',
                    );
                    $counts['manual_review']++;
                    continue;
                }

                try {
                    $absolutePath = $this->absoluteStoragePath((string) $item->storagePath);
                    $text = $this->textExtractor->extract($absolutePath);
                    $candidates = $this->metricExtractor->extract($text);
                    $this->store->markExtracted($item->documentId, $text, $candidates);
                    $counts['extracted']++;
                    $counts['metric_candidates'] += count($candidates);
                    $this->logger->info('Filing document text extracted.', [
                        'document_id' => $item->documentId,
                        'text_characters' => mb_strlen($text),
                        'metric_candidates' => count($candidates),
                    ]);
                } catch (ExtractionException $exception) {
                    $this->store->markManualReview($item->documentId, $exception->getMessage());
                    $counts['manual_review']++;
                } catch (Throwable $exception) {
                    $this->store->markExtractionFailure($item->documentId, $exception->getMessage());
                    $counts['extraction_failed']++;
                    $this->logger->error('Filing document extraction failed.', [
                        'document_id' => $item->documentId,
                        'exception' => $exception::class,
                        'message' => $exception->getMessage(),
                    ]);
                }
            }

            return $counts;
        } finally {
            $this->store->releaseLock();
        }
    }

    private function absoluteStoragePath(string $relativePath): string
    {
        if ($relativePath === '' || str_starts_with($relativePath, '/') || str_contains($relativePath, '..')) {
            throw new RuntimeException('Stored document path is invalid.');
        }

        $root = realpath($this->storageRoot);
        $path = realpath(rtrim($this->storageRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $relativePath);

        if ($root === false || $path === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Stored document path escapes the private document directory.');
        }

        return $path;
    }
}
