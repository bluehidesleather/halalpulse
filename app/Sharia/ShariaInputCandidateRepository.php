<?php

declare(strict_types=1);

namespace HalalPulse\Sharia;

use InvalidArgumentException;
use PDO;
use Throwable;

final readonly class ShariaInputCandidateRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return list<array<string, mixed>> */
    public function forCompany(int $companyId, int $limit = 100): array
    {
        $limit = max(1, min(250, $limit));
        $statement = $this->pdo->prepare(
            <<<'SQL'
            SELECT
                sic.*,
                nii.source_filename,
                nii.xbrl_sha256,
                fr.statement_scope,
                fr.reporting_period_type,
                u.display_name AS reviewed_by_name
            FROM sharia_input_candidates sic
            INNER JOIN nse_integrated_feed_items nii ON nii.id = sic.integrated_item_id
            INNER JOIN financial_results fr ON fr.integrated_item_id = sic.integrated_item_id
            LEFT JOIN users u ON u.id = sic.reviewed_by_user_id
            WHERE sic.company_id = :company_id
            ORDER BY
                CASE sic.review_status WHEN 'pending' THEN 0 WHEN 'accepted' THEN 1 ELSE 2 END,
                sic.period_end DESC,
                sic.mapping_confidence DESC,
                sic.id DESC
            LIMIT
            SQL
            . ' ' . $limit
        );
        $statement->execute(['company_id' => $companyId]);

        return $statement->fetchAll();
    }

    /** @param list<string> $allowedMetricKeys */
    public function accept(int $candidateId, int $companyId, int $userId, array $allowedMetricKeys): int
    {
        if ($candidateId < 1 || $companyId < 1 || $userId < 1) {
            throw new InvalidArgumentException('Candidate acceptance identifiers are invalid.');
        }

        $this->pdo->beginTransaction();

        try {
            $candidate = $this->lockPendingCandidate($candidateId, $companyId);
            $metricKey = (string) $candidate['metric_key'];
            if (!in_array($metricKey, $allowedMetricKeys, true)) {
                throw new InvalidArgumentException('The candidate is not used by the active Sharia policy.');
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
                'period_end' => $candidate['period_end'],
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
                    source_integrated_item_id,
                    source_fact_name,
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
                    NULL,
                    :source_integrated_item_id,
                    :source_fact_name,
                    :evidence_note,
                    'current',
                    :accepted_by_user_id,
                    CURRENT_TIMESTAMP
                )
                SQL
            );
            $insert->execute([
                'company_id' => $companyId,
                'period_end' => $candidate['period_end'],
                'metric_key' => $metricKey,
                'value' => $candidate['candidate_value'],
                'currency' => $candidate['currency'],
                'scale_label' => $candidate['scale_label'],
                'source_integrated_item_id' => $candidate['integrated_item_id'],
                'source_fact_name' => $candidate['source_fact_name'],
                'evidence_note' => $this->acceptedEvidenceNote($candidate),
                'accepted_by_user_id' => $userId,
            ]);
            $inputId = (int) $this->pdo->lastInsertId();

            $review = $this->pdo->prepare(
                <<<'SQL'
                UPDATE sharia_input_candidates
                SET review_status = 'accepted',
                    reviewed_by_user_id = :user_id,
                    reviewed_at = CURRENT_TIMESTAMP,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id
                  AND review_status = 'pending'
                SQL
            );
            $review->execute(['user_id' => $userId, 'id' => $candidateId]);
            if ($review->rowCount() !== 1) {
                throw new InvalidArgumentException('The candidate is no longer pending.');
            }

            $this->pdo->commit();

            return $inputId;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    public function reject(int $candidateId, int $companyId, int $userId): void
    {
        if ($candidateId < 1 || $companyId < 1 || $userId < 1) {
            throw new InvalidArgumentException('Candidate rejection identifiers are invalid.');
        }

        $statement = $this->pdo->prepare(
            <<<'SQL'
            UPDATE sharia_input_candidates
            SET review_status = 'rejected',
                reviewed_by_user_id = :user_id,
                reviewed_at = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
              AND company_id = :company_id
              AND review_status = 'pending'
            SQL
        );
        $statement->execute([
            'user_id' => $userId,
            'id' => $candidateId,
            'company_id' => $companyId,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new InvalidArgumentException('The candidate was not found or is no longer pending.');
        }
    }

    /** @return array<string, mixed> */
    private function lockPendingCandidate(int $candidateId, int $companyId): array
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
            SELECT *
            FROM sharia_input_candidates
            WHERE id = :id
              AND company_id = :company_id
              AND review_status = 'pending'
            FOR UPDATE
            SQL
        );
        $statement->execute(['id' => $candidateId, 'company_id' => $companyId]);
        $candidate = $statement->fetch();
        if (!is_array($candidate)) {
            throw new InvalidArgumentException('The candidate was not found or is no longer pending.');
        }

        return $candidate;
    }

    /** @param array<string, mixed> $candidate */
    private function acceptedEvidenceNote(array $candidate): string
    {
        $note = sprintf(
            'Accepted from NSE Integrated Filing XBRL fact %s (%s), candidate confidence %d%%. %s',
            (string) $candidate['source_fact_name'],
            (string) $candidate['source_context_ref'],
            (int) $candidate['mapping_confidence'],
            (string) $candidate['mapping_reason'],
        );

        return mb_substr($note, 0, 1000);
    }
}
