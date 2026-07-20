<?php

declare(strict_types=1);

namespace HalalPulse\Documents;

use DateTimeImmutable;
use PDO;
use Throwable;

final class DocumentStore
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function acquireLock(): bool
    {
        $statement = $this->pdo->query("SELECT GET_LOCK('halalpulse:documents', 0)");

        return (int) $statement->fetchColumn() === 1;
    }

    public function releaseLock(): void
    {
        $this->pdo->query("SELECT RELEASE_LOCK('halalpulse:documents')");
    }

    public function seedPending(int $limit = 100): int
    {
        $limit = max(1, min(500, $limit));
        $statement = $this->pdo->prepare(
            <<<'SQL'
            INSERT INTO filing_documents (filing_id, source_url)
            SELECT f.id, f.attachment_url
            FROM filings f
            LEFT JOIN filing_documents fd ON fd.filing_id = f.id
            WHERE fd.id IS NULL
              AND f.is_quarterly_result_candidate = 1
              AND f.attachment_url IS NOT NULL
              AND f.attachment_url <> ''
            ORDER BY f.announced_at ASC, f.id ASC
            LIMIT
            SQL
            . ' ' . $limit
        );
        $statement->execute();

        return $statement->rowCount();
    }

    public function recoverStaleDownloads(int $olderThanMinutes = 15): int
    {
        $olderThanMinutes = max(5, min(120, $olderThanMinutes));
        $statement = $this->pdo->prepare(
            <<<'SQL'
            UPDATE filing_documents
            SET download_status = 'failed',
                last_error = 'Previous download was interrupted and may be retried.',
                updated_at = CURRENT_TIMESTAMP
            WHERE download_status = 'downloading'
              AND updated_at < (CURRENT_TIMESTAMP - INTERVAL
            SQL
            . ' ' . $olderThanMinutes . ' MINUTE)'
        );
        $statement->execute();

        return $statement->rowCount();
    }

    /** @return list<DocumentQueueItem> */
    public function nextDownloads(int $limit, int $maxAttempts = 3): array
    {
        $limit = max(1, min(20, $limit));
        $maxAttempts = max(1, min(10, $maxAttempts));
        $statement = $this->pdo->query(
            <<<'SQL'
            SELECT
                fd.id AS document_id,
                fd.filing_id,
                fd.source_url,
                f.exchange,
                f.announced_at,
                fd.storage_path
            FROM filing_documents fd
            INNER JOIN filings f ON f.id = fd.filing_id
            WHERE fd.download_status IN ('pending', 'failed')
              AND fd.download_attempts <
            SQL
            . ' ' . $maxAttempts
            . ' ORDER BY f.announced_at ASC, fd.id ASC LIMIT ' . $limit
        );

        return array_map(fn (array $row): DocumentQueueItem => $this->hydrate($row), $statement->fetchAll());
    }

    /** @return list<DocumentQueueItem> */
    public function nextExtractions(int $limit): array
    {
        $limit = max(1, min(20, $limit));
        $statement = $this->pdo->query(
            <<<'SQL'
            SELECT
                fd.id AS document_id,
                fd.filing_id,
                fd.source_url,
                f.exchange,
                f.announced_at,
                fd.storage_path
            FROM filing_documents fd
            INNER JOIN filings f ON f.id = fd.filing_id
            WHERE fd.download_status = 'downloaded'
              AND fd.extraction_status = 'pending'
              AND fd.storage_path IS NOT NULL
            ORDER BY f.announced_at ASC, fd.id ASC
            LIMIT
            SQL
            . ' ' . $limit
        );

        return array_map(fn (array $row): DocumentQueueItem => $this->hydrate($row), $statement->fetchAll());
    }

    public function markDownloading(int $documentId): void
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
            UPDATE filing_documents
            SET download_status = 'downloading',
                download_attempts = download_attempts + 1,
                last_error = NULL,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
            SQL
        );
        $statement->execute(['id' => $documentId]);
    }

    public function markDownloaded(int $documentId, DownloadedDocument $file): void
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
            UPDATE filing_documents
            SET download_status = 'downloaded',
                extraction_status = 'pending',
                storage_path = :storage_path,
                mime_type = :mime_type,
                file_size_bytes = :file_size_bytes,
                sha256 = :sha256,
                last_error = NULL,
                downloaded_at = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
            SQL
        );
        $statement->execute([
            'storage_path' => $file->relativePath,
            'mime_type' => $file->mimeType,
            'file_size_bytes' => $file->sizeBytes,
            'sha256' => $file->sha256,
            'id' => $documentId,
        ]);
    }

    public function markDownloadFailure(int $documentId, string $message): void
    {
        $this->markDownloadState($documentId, 'failed', 'pending', $message);
    }

    public function markUnsupported(int $documentId, string $message): void
    {
        $this->markDownloadState($documentId, 'unsupported', 'manual_review', $message);
    }

    /** @param list<MetricCandidate> $candidates */
    public function markExtracted(int $documentId, string $text, array $candidates): void
    {
        $this->pdo->beginTransaction();

        try {
            $statement = $this->pdo->prepare(
                <<<'SQL'
                UPDATE filing_documents
                SET extraction_status = 'extracted',
                    extracted_text = :extracted_text,
                    last_error = NULL,
                    extracted_at = CURRENT_TIMESTAMP,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id
                SQL
            );
            $statement->execute(['extracted_text' => $text, 'id' => $documentId]);

            $delete = $this->pdo->prepare(
                "DELETE FROM document_metric_candidates WHERE document_id = :document_id AND review_status = 'pending'"
            );
            $delete->execute(['document_id' => $documentId]);

            $insert = $this->pdo->prepare(
                <<<'SQL'
                INSERT INTO document_metric_candidates (
                    document_id,
                    metric_key,
                    statement_scope,
                    period_label,
                    current_value,
                    comparison_value,
                    currency,
                    scale_label,
                    confidence,
                    evidence_snippet
                ) VALUES (
                    :document_id,
                    :metric_key,
                    :statement_scope,
                    :period_label,
                    :current_value,
                    :comparison_value,
                    :currency,
                    :scale_label,
                    :confidence,
                    :evidence_snippet
                )
                SQL
            );

            foreach ($candidates as $candidate) {
                $insert->execute([
                    'document_id' => $documentId,
                    'metric_key' => $candidate->metricKey,
                    'statement_scope' => $candidate->statementScope,
                    'period_label' => $candidate->periodLabel,
                    'current_value' => $candidate->currentValue,
                    'comparison_value' => $candidate->comparisonValue,
                    'currency' => $candidate->currency,
                    'scale_label' => $candidate->scaleLabel,
                    'confidence' => $candidate->confidence,
                    'evidence_snippet' => $candidate->evidenceSnippet,
                ]);
            }

            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    public function markManualReview(int $documentId, string $message): void
    {
        $this->markExtractionState($documentId, 'manual_review', $message);
    }

    public function markExtractionFailure(int $documentId, string $message): void
    {
        $this->markExtractionState($documentId, 'failed', $message);
    }

    private function markDownloadState(int $documentId, string $downloadStatus, string $extractionStatus, string $message): void
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
            UPDATE filing_documents
            SET download_status = :download_status,
                extraction_status = :extraction_status,
                last_error = :last_error,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
            SQL
        );
        $statement->execute([
            'download_status' => $downloadStatus,
            'extraction_status' => $extractionStatus,
            'last_error' => mb_substr($message, 0, 4000),
            'id' => $documentId,
        ]);
    }

    private function markExtractionState(int $documentId, string $status, string $message): void
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
            UPDATE filing_documents
            SET extraction_status = :status,
                last_error = :last_error,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
            SQL
        );
        $statement->execute([
            'status' => $status,
            'last_error' => mb_substr($message, 0, 4000),
            'id' => $documentId,
        ]);
    }

    private function hydrate(array $row): DocumentQueueItem
    {
        return new DocumentQueueItem(
            documentId: (int) $row['document_id'],
            filingId: (int) $row['filing_id'],
            exchange: (string) $row['exchange'],
            sourceUrl: (string) $row['source_url'],
            announcedAt: new DateTimeImmutable((string) $row['announced_at']),
            storagePath: isset($row['storage_path']) ? (string) $row['storage_path'] : null,
        );
    }
}
