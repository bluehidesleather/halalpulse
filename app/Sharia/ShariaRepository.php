<?php

declare(strict_types=1);

namespace HalalPulse\Sharia;

use InvalidArgumentException;
use PDO;
use Throwable;

final readonly class ShariaRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array{companies: int, reviewed: int, passed: int, failed: int, insufficient: int} */
    public function summary(): array
    {
        $row = $this->pdo->query(
            <<<'SQL'
            SELECT
                (SELECT COUNT(*) FROM companies WHERE is_active = 1) AS companies,
                (SELECT COUNT(DISTINCT company_id) FROM company_sharia_activity_reviews) AS reviewed,
                (SELECT COUNT(*) FROM sharia_screenings s WHERE s.status = 'passed' AND s.id = (SELECT MAX(s2.id) FROM sharia_screenings s2 WHERE s2.company_id = s.company_id)) AS passed,
                (SELECT COUNT(*) FROM sharia_screenings s WHERE s.status = 'failed' AND s.id = (SELECT MAX(s2.id) FROM sharia_screenings s2 WHERE s2.company_id = s.company_id)) AS failed,
                (SELECT COUNT(*) FROM sharia_screenings s WHERE s.status = 'insufficient' AND s.id = (SELECT MAX(s2.id) FROM sharia_screenings s2 WHERE s2.company_id = s.company_id)) AS insufficient
            SQL
        )->fetch();

        return [
            'companies' => (int) ($row['companies'] ?? 0),
            'reviewed' => (int) ($row['reviewed'] ?? 0),
            'passed' => (int) ($row['passed'] ?? 0),
            'failed' => (int) ($row['failed'] ?? 0),
            'insufficient' => (int) ($row['insufficient'] ?? 0),
        ];
    }

    public function activePolicy(): ?ShariaPolicy
    {
        $row = $this->pdo->query(
            <<<'SQL'
            SELECT *
            FROM sharia_policies
            WHERE is_active = 1
            ORDER BY activated_at DESC, id DESC
            LIMIT 1
            SQL
        )->fetch();

        return is_array($row) ? ShariaPolicy::fromDatabase($row) : null;
    }

    /** @return list<array<string, mixed>> */
    public function companies(int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));

        return $this->pdo->query(
            <<<'SQL'
            SELECT
                c.id,
                c.exchange,
                c.symbol,
                c.company_name,
                c.sector,
                (SELECT ar.activity_status FROM company_sharia_activity_reviews ar WHERE ar.company_id = c.id ORDER BY ar.id DESC LIMIT 1) AS activity_status,
                (SELECT ss.status FROM sharia_screenings ss WHERE ss.company_id = c.id ORDER BY ss.id DESC LIMIT 1) AS screening_status,
                (SELECT ss.compliance_rank FROM sharia_screenings ss WHERE ss.company_id = c.id ORDER BY ss.id DESC LIMIT 1) AS compliance_rank,
                (SELECT ss.period_end FROM sharia_screenings ss WHERE ss.company_id = c.id ORDER BY ss.id DESC LIMIT 1) AS screening_period,
                (SELECT ss.computed_at FROM sharia_screenings ss WHERE ss.company_id = c.id ORDER BY ss.id DESC LIMIT 1) AS screened_at
            FROM companies c
            WHERE c.is_active = 1
            ORDER BY c.company_name, c.exchange, c.symbol
            LIMIT
            SQL
            . ' ' . $limit
        )->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function company(int $companyId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM companies WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $companyId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    public function latestActivityReview(int $companyId): ?array
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
            SELECT ar.*, u.display_name AS reviewer_name
            FROM company_sharia_activity_reviews ar
            INNER JOIN users u ON u.id = ar.reviewed_by_user_id
            WHERE ar.company_id = :company_id
            ORDER BY ar.id DESC
            LIMIT 1
            SQL
        );
        $statement->execute(['company_id' => $companyId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function saveActivityReview(
        int $companyId,
        string $status,
        string $description,
        ?string $sourceUrl,
        string $evidenceNote,
        int $userId,
    ): void {
        if (!in_array($status, ['pending', 'permissible', 'prohibited', 'mixed'], true)) {
            throw new InvalidArgumentException('Activity review status is invalid.');
        }

        $statement = $this->pdo->prepare(
            <<<'SQL'
            INSERT INTO company_sharia_activity_reviews (
                company_id,
                activity_status,
                activity_description,
                evidence_source_url,
                evidence_note,
                reviewed_by_user_id,
                reviewed_at
            ) VALUES (
                :company_id,
                :activity_status,
                :activity_description,
                :evidence_source_url,
                :evidence_note,
                :reviewed_by_user_id,
                CURRENT_TIMESTAMP
            )
            SQL
        );
        $statement->execute([
            'company_id' => $companyId,
            'activity_status' => $status,
            'activity_description' => $description,
            'evidence_source_url' => $sourceUrl,
            'evidence_note' => $evidenceNote,
            'reviewed_by_user_id' => $userId,
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function documentsForCompany(int $companyId, int $limit = 50): array
    {
        $limit = max(1, min(100, $limit));
        $statement = $this->pdo->prepare(
            <<<'SQL'
            SELECT fd.id, f.announced_at, f.subject, fd.sha256
            FROM filing_documents fd
            INNER JOIN filings f ON f.id = fd.filing_id
            WHERE f.company_id = :company_id
              AND fd.download_status = 'downloaded'
            ORDER BY f.announced_at DESC, fd.id DESC
            LIMIT
            SQL
            . ' ' . $limit
        );
        $statement->execute(['company_id' => $companyId]);

        return $statement->fetchAll();
    }

    public function documentBelongsToCompany(int $documentId, int $companyId): bool
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
            SELECT COUNT(*)
            FROM filing_documents fd
            INNER JOIN filings f ON f.id = fd.filing_id
            WHERE fd.id = :document_id
              AND f.company_id = :company_id
              AND fd.download_status = 'downloaded'
            SQL
        );
        $statement->execute(['document_id' => $documentId, 'company_id' => $companyId]);

        return (int) $statement->fetchColumn() === 1;
    }

    public function saveFinancialInput(
        int $companyId,
        string $periodEnd,
        string $metricKey,
        string $value,
        string $currency,
        string $scaleLabel,
        ?int $sourceDocumentId,
        string $evidenceNote,
        int $userId,
    ): void {
        $this->pdo->beginTransaction();

        try {
            $lock = $this->pdo->prepare('SELECT id FROM companies WHERE id = :id FOR UPDATE');
            $lock->execute(['id' => $companyId]);
            if ($lock->fetchColumn() === false) {
                throw new InvalidArgumentException('Company not found.');
            }

            $supersede = $this->pdo->prepare(
                <<<'SQL'
                UPDATE sharia_financial_inputs
                SET evidence_status = 'superseded'
                WHERE company_id = :company_id
                  AND period_end = :period_end
                  AND metric_key = :metric_key
                  AND evidence_status = 'current'
                SQL
            );
            $supersede->execute([
                'company_id' => $companyId,
                'period_end' => $periodEnd,
                'metric_key' => $metricKey,
            ]);

            $insert = $this->pdo->prepare(
                <<<'SQL'
                INSERT INTO sharia_financial_inputs (
                    company_id,
                    period_end,
                    metric_key,
                    value,
                    currency,
                    scale_label,
                    source_document_id,
                    evidence_note,
                    evidence_status,
                    accepted_by_user_id,
                    accepted_at
                ) VALUES (
                    :company_id,
                    :period_end,
                    :metric_key,
                    :value,
                    :currency,
                    :scale_label,
                    :source_document_id,
                    :evidence_note,
                    'current',
                    :accepted_by_user_id,
                    CURRENT_TIMESTAMP
                )
                SQL
            );
            $insert->execute([
                'company_id' => $companyId,
                'period_end' => $periodEnd,
                'metric_key' => $metricKey,
                'value' => $value,
                'currency' => $currency,
                'scale_label' => $scaleLabel,
                'source_document_id' => $sourceDocumentId,
                'evidence_note' => $evidenceNote,
                'accepted_by_user_id' => $userId,
            ]);

            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    /** @return array<string, array<string, mixed>> */
    public function inputsForPeriod(int $companyId, string $periodEnd): array
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
            SELECT
                sfi.*,
                u.display_name AS accepted_by_name
            FROM sharia_financial_inputs sfi
            INNER JOIN users u ON u.id = sfi.accepted_by_user_id
            WHERE sfi.company_id = :company_id
              AND sfi.period_end = :period_end
              AND sfi.evidence_status = 'current'
            ORDER BY sfi.metric_key
            SQL
        );
        $statement->execute(['company_id' => $companyId, 'period_end' => $periodEnd]);

        $inputs = [];
        foreach ($statement->fetchAll() as $row) {
            $inputs[(string) $row['metric_key']] = $row;
        }

        return $inputs;
    }

    /** @return list<string> */
    public function periods(int $companyId): array
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
            SELECT DISTINCT period_end
            FROM sharia_financial_inputs
            WHERE company_id = :company_id
              AND evidence_status = 'current'
            ORDER BY period_end DESC
            LIMIT 20
            SQL
        );
        $statement->execute(['company_id' => $companyId]);

        return array_map(static fn (array $row): string => (string) $row['period_end'], $statement->fetchAll());
    }

    public function recordScreening(
        int $companyId,
        ShariaPolicy $policy,
        string $periodEnd,
        ShariaScreeningResult $result,
        int $userId,
    ): int {
        $statement = $this->pdo->prepare(
            <<<'SQL'
            INSERT INTO sharia_screenings (
                company_id,
                policy_id,
                period_end,
                status,
                compliance_rank,
                activity_status,
                ratio_results,
                reasons,
                input_snapshot,
                computed_by_user_id,
                computed_at
            ) VALUES (
                :company_id,
                :policy_id,
                :period_end,
                :status,
                :compliance_rank,
                :activity_status,
                :ratio_results,
                :reasons,
                :input_snapshot,
                :computed_by_user_id,
                CURRENT_TIMESTAMP
            )
            SQL
        );
        $statement->execute([
            'company_id' => $companyId,
            'policy_id' => $policy->id,
            'period_end' => $periodEnd,
            'status' => $result->status,
            'compliance_rank' => $result->complianceRank,
            'activity_status' => $result->activityStatus,
            'ratio_results' => json_encode($result->ratioResults, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'reasons' => json_encode($result->reasons, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'input_snapshot' => json_encode($result->inputSnapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'computed_by_user_id' => $userId,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return list<array<string, mixed>> */
    public function screeningHistory(int $companyId, int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        $statement = $this->pdo->prepare(
            <<<'SQL'
            SELECT ss.*, sp.version AS policy_version, sp.name AS policy_name, u.display_name AS computed_by_name
            FROM sharia_screenings ss
            INNER JOIN sharia_policies sp ON sp.id = ss.policy_id
            INNER JOIN users u ON u.id = ss.computed_by_user_id
            WHERE ss.company_id = :company_id
            ORDER BY ss.id DESC
            LIMIT
            SQL
            . ' ' . $limit
        );
        $statement->execute(['company_id' => $companyId]);

        return $statement->fetchAll();
    }
}
