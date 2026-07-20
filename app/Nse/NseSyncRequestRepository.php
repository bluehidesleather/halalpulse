<?php

declare(strict_types=1);

namespace HalalPulse\Nse;

use DateTimeImmutable;
use PDO;

final class NseSyncRequestRepository
{
    private const SOURCE_KEY = 'nse_integrated_rss';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{id: int, created: bool, status: string} */
    public function request(int $userId, int $cooldownSeconds): array
    {
        $cooldownSeconds = max(300, min(3600, $cooldownSeconds));
        $locked = (int) $this->pdo->query("SELECT GET_LOCK('halalpulse:nse:manual-request', 2)")->fetchColumn() === 1;
        if (!$locked) {
            throw new \RuntimeException('Unable to reserve the NSE manual-sync request lock.');
        }

        try {
            $cutoff = (new DateTimeImmutable())
                ->modify(sprintf('-%d seconds', $cooldownSeconds))
                ->format('Y-m-d H:i:s');
            $statement = $this->pdo->prepare(
                <<<'SQL'
                SELECT id, status
                FROM nse_sync_requests
                WHERE source_key = :source_key
                  AND (
                    status IN ('pending', 'running')
                    OR requested_at >= :cutoff
                  )
                ORDER BY id DESC
                LIMIT 1
                SQL
            );
            $statement->execute(['source_key' => self::SOURCE_KEY, 'cutoff' => $cutoff]);
            $existing = $statement->fetch();
            if (is_array($existing)) {
                return [
                    'id' => (int) $existing['id'],
                    'created' => false,
                    'status' => (string) $existing['status'],
                ];
            }

            $insert = $this->pdo->prepare(
                <<<'SQL'
                INSERT INTO nse_sync_requests (source_key, requested_by_user_id, status, requested_at)
                VALUES (:source_key, :user_id, 'pending', CURRENT_TIMESTAMP)
                SQL
            );
            $insert->execute(['source_key' => self::SOURCE_KEY, 'user_id' => $userId]);

            return ['id' => (int) $this->pdo->lastInsertId(), 'created' => true, 'status' => 'pending'];
        } finally {
            $this->pdo->query("SELECT RELEASE_LOCK('halalpulse:nse:manual-request')")->fetchColumn();
        }
    }

    /** @return array{id: int}|null */
    public function reservePending(): ?array
    {
        $this->pdo->beginTransaction();

        try {
            $this->pdo->exec(
                <<<'SQL'
                UPDATE nse_sync_requests
                SET status = 'pending', started_at = NULL, error_message = 'Recovered after an interrupted worker.'
                WHERE source_key = 'nse_integrated_rss'
                  AND status = 'running'
                  AND started_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 30 MINUTE)
                SQL
            );
            $statement = $this->pdo->prepare(
                <<<'SQL'
                SELECT id FROM nse_sync_requests
                WHERE source_key = :source_key AND status = 'pending'
                ORDER BY id LIMIT 1 FOR UPDATE
                SQL
            );
            $statement->execute(['source_key' => self::SOURCE_KEY]);
            $id = $statement->fetchColumn();
            if ($id === false) {
                $this->pdo->commit();
                return null;
            }

            $update = $this->pdo->prepare(
                "UPDATE nse_sync_requests SET status = 'running', started_at = CURRENT_TIMESTAMP WHERE id = :id"
            );
            $update->execute(['id' => $id]);
            $this->pdo->commit();

            return ['id' => (int) $id];
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    public function complete(int $id, array $result): void
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
            UPDATE nse_sync_requests
            SET status = 'succeeded', result_payload = :result, error_message = NULL, finished_at = CURRENT_TIMESTAMP
            WHERE id = :id
            SQL
        );
        $statement->execute([
            'result' => json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'id' => $id,
        ]);
    }

    public function fail(int $id, string $error): void
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
            UPDATE nse_sync_requests
            SET status = 'failed', error_message = :error, finished_at = CURRENT_TIMESTAMP
            WHERE id = :id
            SQL
        );
        $statement->execute(['error' => mb_substr($error, 0, 4000), 'id' => $id]);
    }

    public function returnToQueue(int $id): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE nse_sync_requests SET status = 'pending', started_at = NULL WHERE id = :id"
        );
        $statement->execute(['id' => $id]);
    }
}
