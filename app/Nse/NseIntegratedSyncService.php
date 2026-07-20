<?php

declare(strict_types=1);

namespace HalalPulse\Nse;

use HalalPulse\Http\HttpClient;
use HalalPulse\Support\JsonLogger;
use RuntimeException;
use Throwable;

final class NseIntegratedSyncService
{
    public function __construct(
        private readonly HttpClient $http,
        private readonly IntegratedRssParser $rssParser,
        private readonly IntegratedXbrlParser $xbrlParser,
        private readonly XmlArchive $archive,
        private readonly NseIntegratedStore $store,
        private readonly JsonLogger $logger,
        private readonly string $feedUrl,
        private readonly int $batchSize = 20,
    ) {
    }

    /**
     * @return array{
     *   source: string, status: string, trigger: string, feed_items: int,
     *   discovered: int, queued: int, processed: int, failed: int
     * }
     */
    public function sync(string $trigger = 'scheduled', ?int $syncRequestId = null): array
    {
        if (!NseIntegratedUrl::isAllowedFeed($this->feedUrl)) {
            throw new RuntimeException('NSE Integrated Filing RSS endpoint does not match the official contract.');
        }

        if (!$this->store->acquireLock()) {
            return [
                'source' => 'NSE Integrated Filing RSS',
                'status' => 'skipped',
                'trigger' => $trigger,
                'feed_items' => 0,
                'discovered' => 0,
                'queued' => 0,
                'processed' => 0,
                'failed' => 0,
            ];
        }

        $runId = 0;
        $startedAt = hrtime(true);

        try {
            $runId = $this->store->startRun($trigger, $syncRequestId);
            $response = $this->http->get($this->feedUrl, [
                'Accept' => 'application/rss+xml, application/xml;q=0.9, text/xml;q=0.8',
            ]);
            $feed = $this->rssParser->parse($response->body);
            $feedArchive = $this->archive->storeFeed($response->body, $feed->lastBuildAt);
            $discovered = $this->store->discover($feed);
            $this->store->recordFeed($runId, $feed, $feedArchive, $discovered);

            if ($feed->warnings !== []) {
                $this->logger->info('NSE Integrated Filing RSS included skipped items.', [
                    'source_rows' => $feed->sourceRows,
                    'warnings' => $feed->warnings,
                ]);
            }

            $queue = $this->store->queuedItems($this->batchSize);
            $counts = ['queued' => count($queue), 'processed' => 0, 'failed' => 0];

            foreach ($queue as $queued) {
                $this->store->markProcessing($queued->id);

                try {
                    $xbrlResponse = $this->http->get($queued->item->sourceUrl, [
                        'Accept' => 'application/xml, text/xml;q=0.9',
                    ]);
                    $result = $this->xbrlParser->parse($xbrlResponse->body);
                    $xbrlArchive = $this->archive->storeXbrl($xbrlResponse->body, $queued->item);
                    $filingId = $this->store->completeItem($queued, $result, $xbrlArchive);
                    $counts['processed']++;
                    $this->logger->info('NSE Integrated Filing XBRL processed.', [
                        'filing_id' => $filingId,
                        'symbol' => $result->metadata['symbol'],
                        'period_end' => $result->metadata['period_end'],
                        'filing_type' => $queued->item->filingType,
                    ]);
                } catch (Throwable $exception) {
                    $counts['failed']++;
                    $this->store->recordItemFailure($queued, $exception->getMessage());
                    $this->logger->error('NSE Integrated Filing XBRL processing failed.', [
                        'item_id' => $queued->id,
                        'source_url_hash' => hash('sha256', $queued->item->sourceUrl),
                        'exception' => $exception::class,
                        'message' => $exception->getMessage(),
                    ]);
                }
            }

            $status = $counts['failed'] > 0 ? 'partial' : 'succeeded';
            $this->store->finishRun($runId, $status, $counts, $this->durationMs($startedAt));
            $result = [
                'source' => 'NSE Integrated Filing RSS',
                'status' => $status,
                'trigger' => $trigger,
                'feed_items' => $feed->sourceRows,
                'discovered' => $discovered,
                'queued' => $counts['queued'],
                'processed' => $counts['processed'],
                'failed' => $counts['failed'],
            ];
            $this->logger->info('NSE Integrated Filing RSS synchronization completed.', $result);

            return $result;
        } catch (Throwable $exception) {
            if ($runId > 0) {
                $this->store->failRun($runId, $exception->getMessage(), $this->durationMs($startedAt));
            }

            $this->logger->error('NSE Integrated Filing RSS synchronization failed.', [
                'trigger' => $trigger,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            throw new RuntimeException('NSE Integrated Filing RSS synchronization failed.', 0, $exception);
        } finally {
            $this->store->releaseLock();
        }
    }

    private function durationMs(int $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }
}
