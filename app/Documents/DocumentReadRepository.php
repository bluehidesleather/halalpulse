<?php

declare(strict_types=1);

namespace HalalPulse\Documents;

use InvalidArgumentException;
use PDO;

final class DocumentReadRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{total: int, pending_download: int, downloaded: int, extracted: int, manual_review: int, failed: int, pending_metrics: int} */
    public function summary(): array
    {
        $row = $this->pdo->query(
            <<<'SQL'
            SELECT
                (SELECT COUNT(*) FROM filing_documents) AS total,
                (SELECT COUNT(*) FROM filing_documents WHERE download_status IN ('pending', 'downloading')) AS pending_download,
                (SELECT COUNT(*) FROM filing_documents WHERE download_status = 'downloaded') AS downloaded,
                (SELECT COUNT(*) FROM filing_documents WHERE extraction_status = 'extracted') AS extracted,
                (SELECT COUNT(*) FROM filing_documents WHERE extraction_status = 'manual_review') AS manual_review,
                (SELECT COUNT(*) FROM filing_documents WHERE download_status = 'failed' OR extraction_status = 'failed') AS failed,
                (SELECT COUNT(*) FROM document_metric_candidates WHERE review_status = 'pending') AS pending_metrics
            SQL
        )->fetch();

        return [
            'total' => (int) ($row['total'] ?? 0),
            'pending_download' => (int) ($row['pending_download'] ?? 0),
            'downloaded' => (int) ($row['downloaded'] ?? 0),
            'extracted' => (int) ($row['extracted'] ?? 0),
            'manual_review' => (int) ($row['manual_review'] ?? 0),
            'failed' => (int) ($row['failed'] ?? 0),
            'pending_metrics' => (int) ($row['pending_metrics'] ?? 0),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function recent(int $limit = 50): array
    {
        $limit = max(1, min(100, $limit));
        $statement = $this->pdo->query(
            <<<'SQL'
            SELECT
                fd.id AS document_id,
                fd.filing_id,
                fd.download_status,
                fd.extraction_status,
                fd.file_size_bytes,
                fd.download_attempts,
                fd.last_error,
                fd.downloaded_at,
                f.exchange,
                f.announced_at,
                f.subject,
                c.symbol,
                c.company_name,
                (SELECT COUNT(*) FROM document_metric_candidates dmc WHERE dmc.document_id = fd.id) AS metric_count,
                (SELECT COUNT(*) FROM document_metric_candidates dmc WHERE dmc.document_id = fd.id AND dmc.review_status = 'pending') AS pending_metric_count
            FROM filing_documents fd
            INNER JOIN filings f ON f.id = fd.filing_id
            INNER JOIN companies c ON c.id = f.company_id
            ORDER BY fd.updated_at DESC, fd.id DESC
            LIMIT
            SQL
            . ' ' . $limit
        );

        return $statement->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function filingDetail(int $filingId): ?array
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
            SELECT
                f.id AS filing_id,
                f.exchange,
                f.source_id,
                f.category,
                f.subject,
                f.announced_at,
                f.attachment_url,
                f.is_quarterly_result_candidate,
                f.classifier_confidence,
                f.classifier_reason,
                f.processing_status,
                c.symbol,
                c.company_name,
                c.isin,
                fd.id AS document_id,
                fd.download_status,
                fd.extraction_status,
                fd.mime_type,
                fd.file_size_bytes,
                fd.sha256,
                fd.download_attempts,
                fd.last_error,
                fd.downloaded_at,
                fd.extracted_at
            FROM filings f
            INNER JOIN companies c ON c.id = f.company_id
            LEFT JOIN filing_documents fd ON fd.filing_id = f.id
            WHERE f.id = :filing_id
            LIMIT 1
            SQL
        );
        $statement->execute(['filing_id' => $filingId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /** @return list<array<string, mixed>> */
    public function metricsForDocument(int $documentId): array
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
            SELECT
                dmc.id,
                dmc.metric_key,
                dmc.statement_scope,
                dmc.period_label,
                dmc.current_value,
                dmc.comparison_value,
                dmc.currency,
                dmc.scale_label,
                dmc.confidence,
                dmc.evidence_snippet,
                dmc.review_status,
                dmc.reviewed_at,
                u.display_name AS reviewer_name
            FROM document_metric_candidates dmc
            LEFT JOIN users u ON u.id = dmc.reviewed_by_user_id
            WHERE dmc.document_id = :document_id
            ORDER BY FIELD(dmc.metric_key, 'revenue', 'total_income', 'ebitda', 'profit_before_tax', 'net_profit', 'eps'), dmc.id
            SQL
        );
        $statement->execute(['document_id' => $documentId]);

        return $statement->fetchAll();
    }

    public function reviewMetric(int $candidateId, string $status, int $userId): ?int
    {
        if (!in_array($status, ['accepted', 'rejected'], true)) {
            throw new InvalidArgumentException('Metric review status is invalid.');
        }

        $lookup = $this->pdo->prepare(
            <<<'SQL'
            SELECT fd.filing_id
            FROM document_metric_candidates dmc
            INNER JOIN filing_documents fd ON fd.id = dmc.document_id
            WHERE dmc.id = :id
            SQL
        );
        $lookup->execute(['id' => $candidateId]);
        $filingId = $lookup->fetchColumn();

        if ($filingId === false) {
            return null;
        }

        $statement = $this->pdo->prepare(
            <<<'SQL'
            UPDATE document_metric_candidates
            SET review_status = :status,
                reviewed_by_user_id = :user_id,
                reviewed_at = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
              AND review_status = 'pending'
            SQL
        );
        $statement->execute([
            'status' => $status,
            'user_id' => $userId,
            'id' => $candidateId,
        ]);

        if ($statement->rowCount() !== 1) {
            return null;
        }

        return (int) $filingId;
    }

    /** @return array{storage_path: string, sha256: string, file_size_bytes: int}|null */
    public function downloadable(int $documentId): ?array
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
            SELECT storage_path, sha256, file_size_bytes
            FROM filing_documents
            WHERE id = :id
              AND download_status = 'downloaded'
              AND storage_path IS NOT NULL
            LIMIT 1
            SQL
        );
        $statement->execute(['id' => $documentId]);
        $row = $statement->fetch();

        if (!is_array($row)) {
            return null;
        }

        return [
            'storage_path' => (string) $row['storage_path'],
            'sha256' => (string) $row['sha256'],
            'file_size_bytes' => (int) $row['file_size_bytes'],
        ];
    }
}
