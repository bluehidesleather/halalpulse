<?php

declare(strict_types=1);

namespace HalalPulse\Sharia;

use HalalPulse\Nse\IntegratedFinancialResult;
use InvalidArgumentException;
use PDO;

final readonly class ShariaInputCandidateStore
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param list<array{
     *   metric_key: string,
     *   value: string,
     *   currency: string,
     *   scale_label: string,
     *   source_fact_name: string,
     *   source_context_ref: string,
     *   confidence: int,
     *   mapping_reason: string
     * }> $candidates
     */
    public function store(int $integratedItemId, IntegratedFinancialResult $result, array $candidates): int
    {
        if ($integratedItemId < 1 || $candidates === []) {
            return 0;
        }

        $lookup = $this->pdo->prepare(
            <<<'SQL'
            SELECT company_id, period_end
            FROM financial_results
            WHERE integrated_item_id = :integrated_item_id
            LIMIT 1
            SQL
        );
        $lookup->execute(['integrated_item_id' => $integratedItemId]);
        $financialResult = $lookup->fetch();
        if (!is_array($financialResult)) {
            throw new InvalidArgumentException('Financial result must exist before Sharia evidence candidates are stored.');
        }

        $periodEnd = (string) $financialResult['period_end'];
        if ($periodEnd !== (string) ($result->metadata['period_end'] ?? '')) {
            throw new InvalidArgumentException('Sharia evidence candidate period does not match the stored financial result.');
        }

        $insert = $this->pdo->prepare(
            <<<'SQL'
            INSERT IGNORE INTO sharia_input_candidates (
                company_id,
                integrated_item_id,
                period_end,
                metric_key,
                candidate_value,
                currency,
                scale_label,
                source_fact_name,
                source_context_ref,
                mapping_confidence,
                mapping_reason,
                review_status
            ) VALUES (
                :company_id,
                :integrated_item_id,
                :period_end,
                :metric_key,
                :candidate_value,
                :currency,
                :scale_label,
                :source_fact_name,
                :source_context_ref,
                :mapping_confidence,
                :mapping_reason,
                'pending'
            )
            SQL
        );

        $stored = 0;
        foreach ($candidates as $candidate) {
            $insert->execute([
                'company_id' => (int) $financialResult['company_id'],
                'integrated_item_id' => $integratedItemId,
                'period_end' => $periodEnd,
                'metric_key' => $candidate['metric_key'],
                'candidate_value' => $candidate['value'],
                'currency' => $candidate['currency'],
                'scale_label' => $candidate['scale_label'],
                'source_fact_name' => $candidate['source_fact_name'],
                'source_context_ref' => $candidate['source_context_ref'],
                'mapping_confidence' => $candidate['confidence'],
                'mapping_reason' => $candidate['mapping_reason'],
            ]);
            $stored += $insert->rowCount();
        }

        return $stored;
    }
}
