<?php

declare(strict_types=1);

namespace HalalPulse\Ingestion;

use DateTimeImmutable;
use PDO;
use Throwable;

final class FilingStore
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function checkpoint(string $exchange): ?DateTimeImmutable
    {
        $statement = $this->pdo->prepare(
            'SELECT last_successful_announcement_at FROM source_checkpoints WHERE exchange = :exchange'
        );
        $statement->execute(['exchange' => $exchange]);
        $value = $statement->fetchColumn();

        return is_string($value) && $value !== '' ? new DateTimeImmutable($value) : null;
    }

    public function acquireLock(string $exchange): bool
    {
        $statement = $this->pdo->prepare('SELECT GET_LOCK(:lock_name, 0)');
        $statement->execute(['lock_name' => 'halalpulse:poll:' . strtolower($exchange)]);

        return (int) $statement->fetchColumn() === 1;
    }

    public function releaseLock(string $exchange): void
    {
        $statement = $this->pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
        $statement->execute(['lock_name' => 'halalpulse:poll:' . strtolower($exchange)]);
    }

    public function startPollRun(string $exchange): int
    {
        $statement = $this->pdo->prepare(
            "INSERT INTO poll_runs (exchange, status, started_at) VALUES (:exchange, 'running', CURRENT_TIMESTAMP)"
        );
        $statement->execute(['exchange' => $exchange]);

        return (int) $this->pdo->lastInsertId();
    }

    public function completePollRun(int $runId, array $counts, int $durationMs): void
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
            UPDATE poll_runs
            SET status = 'succeeded',
                finished_at = CURRENT_TIMESTAMP,
                records_seen = :seen,
                records_inserted = :inserted,
                candidates_detected = :candidates,
                duration_ms = :duration_ms
            WHERE id = :id
            SQL
        );
        $statement->execute([
            'seen' => $counts['seen'],
            'inserted' => $counts['inserted'],
            'candidates' => $counts['candidates'],
            'duration_ms' => $durationMs,
            'id' => $runId,
        ]);
    }

    public function failPollRun(int $runId, string $error, int $durationMs): void
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
            UPDATE poll_runs
            SET status = 'failed',
                finished_at = CURRENT_TIMESTAMP,
                duration_ms = :duration_ms,
                error_message = :error_message
            WHERE id = :id
            SQL
        );
        $statement->execute([
            'duration_ms' => $durationMs,
            'error_message' => mb_substr($error, 0, 4000),
            'id' => $runId,
        ]);
    }

    public function recordSourceFailure(string $exchange, string $error): void
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
            UPDATE source_checkpoints
            SET consecutive_failures = consecutive_failures + 1,
                last_error = :last_error,
                updated_at = CURRENT_TIMESTAMP
            WHERE exchange = :exchange
            SQL
        );
        $statement->execute([
            'last_error' => mb_substr($error, 0, 4000),
            'exchange' => $exchange,
        ]);
    }

    /** @return array{seen: int, inserted: int, candidates: int} */
    public function storeBatch(array $filings, QuarterlyResultClassifier $classifier): array
    {
        $counts = ['seen' => count($filings), 'inserted' => 0, 'candidates' => 0];

        $this->pdo->beginTransaction();

        try {
            foreach ($filings as $filing) {
                if (!$filing instanceof Filing) {
                    throw new \InvalidArgumentException('Filing batch contains an invalid item.');
                }

                $classification = $classifier->classify($filing);
                $companyId = $this->upsertCompany($filing);
                $inserted = $this->insertFiling($filing, $companyId, $classification);
                $counts['inserted'] += $inserted;

                if ($inserted === 1 && $classification['is_candidate']) {
                    $counts['candidates']++;
                }
            }

            $this->pdo->commit();

            return $counts;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    public function saveSuccessfulCheckpoint(string $exchange, ?DateTimeImmutable $latest): void
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
            INSERT INTO source_checkpoints (exchange, last_successful_announcement_at, last_successful_poll_at)
            VALUES (:exchange, :announcement_at, CURRENT_TIMESTAMP)
            ON DUPLICATE KEY UPDATE
                last_successful_announcement_at = CASE
                    WHEN VALUES(last_successful_announcement_at) IS NULL THEN last_successful_announcement_at
                    WHEN last_successful_announcement_at IS NULL THEN VALUES(last_successful_announcement_at)
                    ELSE GREATEST(last_successful_announcement_at, VALUES(last_successful_announcement_at))
                END,
                last_successful_poll_at = CURRENT_TIMESTAMP,
                consecutive_failures = 0,
                last_error = NULL,
                updated_at = CURRENT_TIMESTAMP
            SQL
        );
        $statement->execute([
            'exchange' => $exchange,
            'announcement_at' => $latest?->format('Y-m-d H:i:s'),
        ]);
    }

    private function upsertCompany(Filing $filing): int
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
            INSERT INTO companies (exchange, symbol, company_name, last_seen_at)
            VALUES (:exchange, :symbol, :company_name, CURRENT_TIMESTAMP)
            ON DUPLICATE KEY UPDATE
                company_name = VALUES(company_name),
                last_seen_at = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
            SQL
        );
        $statement->execute([
            'exchange' => $filing->exchange,
            'symbol' => $filing->symbol,
            'company_name' => $filing->companyName,
        ]);

        $lookup = $this->pdo->prepare(
            'SELECT id FROM companies WHERE exchange = :exchange AND symbol = :symbol'
        );
        $lookup->execute([
            'exchange' => $filing->exchange,
            'symbol' => $filing->symbol,
        ]);

        return (int) $lookup->fetchColumn();
    }

    private function insertFiling(Filing $filing, int $companyId, array $classification): int
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
            INSERT INTO filings (
                company_id,
                exchange,
                source_id,
                category,
                subject,
                announced_at,
                attachment_url,
                payload_hash,
                raw_payload,
                is_quarterly_result_candidate,
                classifier_confidence,
                classifier_reason
            ) VALUES (
                :company_id,
                :exchange,
                :source_id,
                :category,
                :subject,
                :announced_at,
                :attachment_url,
                :payload_hash,
                :raw_payload,
                :is_candidate,
                :confidence,
                :reason
            )
            ON DUPLICATE KEY UPDATE id = id
            SQL
        );
        $statement->execute([
            'company_id' => $companyId,
            'exchange' => $filing->exchange,
            'source_id' => $filing->sourceId,
            'category' => $filing->category,
            'subject' => $filing->subject,
            'announced_at' => $filing->announcedAt->format('Y-m-d H:i:s'),
            'attachment_url' => $filing->attachmentUrl,
            'payload_hash' => $filing->payloadHash(),
            'raw_payload' => json_encode($filing->rawPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'is_candidate' => $classification['is_candidate'] ? 1 : 0,
            'confidence' => $classification['confidence'],
            'reason' => $classification['reason'],
        ]);

        return $statement->rowCount() > 0 ? 1 : 0;
    }
}
