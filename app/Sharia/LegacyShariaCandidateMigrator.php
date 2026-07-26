<?php

declare(strict_types=1);

namespace HalalPulse\Sharia;

use PDO;
use Throwable;

final readonly class LegacyShariaCandidateMigrator
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Convert only pending legacy candidates created before the policy began
     * requiring the exact consolidated total-income denominator.
     *
     * @return array{
     *   migrated: int,
     *   duplicate_retired: int,
     *   fallback_rejected: int,
     *   remaining_pending: int,
     *   legacy_current_inputs: int
     * }
     */
    public function migrate(): array
    {
        $this->pdo->beginTransaction();

        try {
            $duplicateRetired = $this->retireDuplicateDirectCandidates();
            $migrated = $this->migrateDirectTotalIncomeCandidates();
            $fallbackRejected = $this->rejectRemainingRevenueCandidates();
            $remainingPending = $this->countPendingLegacyCandidates();
            $legacyCurrentInputs = $this->countCurrentLegacyInputs();

            $this->pdo->commit();

            return [
                'migrated' => $migrated,
                'duplicate_retired' => $duplicateRetired,
                'fallback_rejected' => $fallbackRejected,
                'remaining_pending' => $remainingPending,
                'legacy_current_inputs' => $legacyCurrentInputs,
            ];
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    private function retireDuplicateDirectCandidates(): int
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
            UPDATE sharia_input_candidates AS legacy
            INNER JOIN sharia_input_candidates AS current_candidate
                ON current_candidate.integrated_item_id = legacy.integrated_item_id
               AND current_candidate.metric_key = 'total_income'
               AND current_candidate.source_fact_name = legacy.source_fact_name
               AND current_candidate.source_context_ref = legacy.source_context_ref
            SET legacy.review_status = 'rejected',
                legacy.reviewed_by_user_id = NULL,
                legacy.reviewed_at = CURRENT_TIMESTAMP,
                legacy.mapping_reason = LEFT(
                    CONCAT(
                        'Retired during total-income key migration because an equivalent total_income candidate already exists. Original mapping: ',
                        legacy.mapping_reason
                    ),
                    500
                )
            WHERE legacy.review_status = 'pending'
              AND legacy.metric_key = 'total_revenue'
              AND legacy.source_fact_name IN ('Income', 'TotalIncome')
            SQL,
        );
        $statement->execute();

        return $statement->rowCount();
    }

    private function migrateDirectTotalIncomeCandidates(): int
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
            UPDATE sharia_input_candidates AS legacy
            LEFT JOIN sharia_input_candidates AS current_candidate
                ON current_candidate.integrated_item_id = legacy.integrated_item_id
               AND current_candidate.metric_key = 'total_income'
               AND current_candidate.source_fact_name = legacy.source_fact_name
               AND current_candidate.source_context_ref = legacy.source_context_ref
            SET legacy.metric_key = 'total_income',
                legacy.mapping_reason = LEFT(
                    CONCAT(
                        'Migrated from the legacy total_revenue key because the retained NSE XBRL fact is direct total-income evidence. Original mapping: ',
                        legacy.mapping_reason
                    ),
                    500
                )
            WHERE legacy.review_status = 'pending'
              AND legacy.metric_key = 'total_revenue'
              AND legacy.source_fact_name IN ('Income', 'TotalIncome')
              AND current_candidate.id IS NULL
            SQL,
        );
        $statement->execute();

        return $statement->rowCount();
    }

    private function rejectRemainingRevenueCandidates(): int
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
            UPDATE sharia_input_candidates
            SET review_status = 'rejected',
                reviewed_by_user_id = NULL,
                reviewed_at = CURRENT_TIMESTAMP,
                mapping_reason = LEFT(
                    CONCAT(
                        'Retired during total-income key migration because the retained fact is not direct consolidated total-income evidence. Original mapping: ',
                        mapping_reason
                    ),
                    500
                )
            WHERE review_status = 'pending'
              AND metric_key = 'total_revenue'
            SQL,
        );
        $statement->execute();

        return $statement->rowCount();
    }

    private function countPendingLegacyCandidates(): int
    {
        $statement = $this->pdo->query(
            <<<'SQL'
            SELECT COUNT(*)
            FROM sharia_input_candidates
            WHERE review_status = 'pending'
              AND metric_key = 'total_revenue'
            SQL,
        );

        return (int) $statement->fetchColumn();
    }

    private function countCurrentLegacyInputs(): int
    {
        $statement = $this->pdo->query(
            <<<'SQL'
            SELECT COUNT(*)
            FROM sharia_financial_inputs
            WHERE evidence_status = 'current'
              AND metric_key = 'total_revenue'
            SQL,
        );

        return (int) $statement->fetchColumn();
    }
}
