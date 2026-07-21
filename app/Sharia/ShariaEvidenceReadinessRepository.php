<?php

declare(strict_types=1);

namespace HalalPulse\Sharia;

use PDO;

final readonly class ShariaEvidenceReadinessRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return list<string> */
    public function periods(int $companyId, int $limit = 24): array
    {
        $limit = max(1, min(100, $limit));
        $statement = $this->pdo->prepare(
            <<<'SQL'
            SELECT period_end
            FROM (
                SELECT period_end
                FROM sharia_financial_inputs
                WHERE company_id = :inputs_company_id
                  AND evidence_status = 'current'
                UNION
                SELECT period_end
                FROM sharia_input_candidates
                WHERE company_id = :candidates_company_id
                UNION
                SELECT period_end
                FROM financial_results
                WHERE company_id = :results_company_id
            ) evidence_periods
            ORDER BY period_end DESC
            LIMIT
            SQL
            . ' ' . $limit
        );
        $statement->execute([
            'inputs_company_id' => $companyId,
            'candidates_company_id' => $companyId,
            'results_company_id' => $companyId,
        ]);

        return array_map(
            static fn (array $row): string => (string) $row['period_end'],
            $statement->fetchAll(),
        );
    }

    /** @return list<array<string, mixed>> */
    public function pendingCandidatesForPeriod(int $companyId, string $periodEnd, int $limit = 50): array
    {
        $limit = max(1, min(100, $limit));
        $statement = $this->pdo->prepare(
            <<<'SQL'
            SELECT
                id,
                metric_key,
                mapping_confidence,
                mapping_reason,
                source_fact_name,
                review_status
            FROM sharia_input_candidates
            WHERE company_id = :company_id
              AND period_end = :period_end
              AND review_status = 'pending'
            ORDER BY mapping_confidence DESC, id DESC
            LIMIT
            SQL
            . ' ' . $limit
        );
        $statement->execute([
            'company_id' => $companyId,
            'period_end' => $periodEnd,
        ]);

        return $statement->fetchAll();
    }
}
