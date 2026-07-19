<?php

declare(strict_types=1);

namespace HalalPulse\Government;

use DateTimeImmutable;
use PDO;
use Throwable;

final readonly class GovernmentAnnouncementStore
{
    public function __construct(private PDO $pdo)
    {
    }

    public function checkpoint(string $source): ?DateTimeImmutable
    {
        $statement = $this->pdo->prepare('SELECT last_successful_announcement_at FROM government_source_checkpoints WHERE source=:source');
        $statement->execute(['source' => $source]);
        $value = $statement->fetchColumn();
        return is_string($value) && $value !== '' ? new DateTimeImmutable($value) : null;
    }

    public function isDue(string $source, int $intervalSeconds): bool
    {
        $statement = $this->pdo->prepare('SELECT last_successful_poll_at FROM government_source_checkpoints WHERE source=:source');
        $statement->execute(['source' => $source]);
        $value = $statement->fetchColumn();
        if (!is_string($value) || $value === '') {
            return true;
        }
        return (new DateTimeImmutable($value))->getTimestamp() <= time() - max(60, $intervalSeconds);
    }

    public function acquireLock(string $source): bool
    {
        $statement = $this->pdo->prepare('SELECT GET_LOCK(:lock_name,0)');
        $statement->execute(['lock_name' => 'halalpulse:government:' . strtolower($source)]);
        return (int) $statement->fetchColumn() === 1;
    }

    public function releaseLock(string $source): void
    {
        $statement = $this->pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
        $statement->execute(['lock_name' => 'halalpulse:government:' . strtolower($source)]);
    }

    public function startPollRun(string $source): int
    {
        $statement = $this->pdo->prepare("INSERT INTO government_poll_runs(source,status,started_at) VALUES(:source,'running',CURRENT_TIMESTAMP)");
        $statement->execute(['source' => $source]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @param list<GovernmentAnnouncement> $announcements @return array{seen:int,inserted:int,candidates:int} */
    public function storeBatch(array $announcements, GovernmentSectorClassifier $classifier): array
    {
        $counts = ['seen' => count($announcements), 'inserted' => 0, 'candidates' => 0];
        $this->pdo->beginTransaction();
        try {
            foreach ($announcements as $announcement) {
                if (!$announcement instanceof GovernmentAnnouncement) {
                    throw new \InvalidArgumentException('Government announcement batch contains an invalid item.');
                }
                $classification = $classifier->classify($announcement);
                $statement = $this->pdo->prepare(
                    <<<'SQL'
                    INSERT INTO government_announcements(
                        source,source_id,category,title,summary,published_at,official_url,payload_hash,raw_payload,
                        classifier_sector,classifier_impact,classifier_confidence,classifier_reason
                    ) VALUES(
                        :source,:source_id,:category,:title,:summary,:published_at,:official_url,:payload_hash,:raw_payload,
                        :classifier_sector,:classifier_impact,:classifier_confidence,:classifier_reason
                    ) ON DUPLICATE KEY UPDATE id=id
                    SQL
                );
                $statement->execute([
                    'source' => $announcement->source,
                    'source_id' => $announcement->sourceId,
                    'category' => $announcement->category,
                    'title' => $announcement->title,
                    'summary' => $announcement->summary,
                    'published_at' => $announcement->publishedAt->format('Y-m-d H:i:s'),
                    'official_url' => $announcement->officialUrl,
                    'payload_hash' => $announcement->payloadHash(),
                    'raw_payload' => json_encode($announcement->rawPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'classifier_sector' => $classification->sector,
                    'classifier_impact' => $classification->suggestedImpact,
                    'classifier_confidence' => $classification->confidence,
                    'classifier_reason' => mb_substr($classification->reason, 0, 1000),
                ]);
                if ($statement->rowCount() > 0) {
                    $counts['inserted']++;
                    if ($classification->sector !== null) {
                        $counts['candidates']++;
                    }
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

    public function completePollRun(int $runId, array $counts, int $durationMs): void
    {
        $statement = $this->pdo->prepare("UPDATE government_poll_runs SET status='succeeded',finished_at=CURRENT_TIMESTAMP,records_seen=:seen,records_inserted=:inserted,candidates_detected=:candidates,duration_ms=:duration_ms WHERE id=:id");
        $statement->execute($counts + ['duration_ms' => $durationMs, 'id' => $runId]);
    }

    public function skipPollRun(int $runId, string $reason): void
    {
        $statement = $this->pdo->prepare("UPDATE government_poll_runs SET status='skipped',finished_at=CURRENT_TIMESTAMP,error_message=:reason WHERE id=:id");
        $statement->execute(['reason' => mb_substr($reason, 0, 4000), 'id' => $runId]);
    }

    public function failPollRun(int $runId, string $error, int $durationMs): void
    {
        $statement = $this->pdo->prepare("UPDATE government_poll_runs SET status='failed',finished_at=CURRENT_TIMESTAMP,duration_ms=:duration_ms,error_message=:error WHERE id=:id");
        $statement->execute(['duration_ms' => $durationMs, 'error' => mb_substr($error, 0, 4000), 'id' => $runId]);
    }

    public function saveSuccessfulCheckpoint(string $source, ?DateTimeImmutable $latest): void
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
            INSERT INTO government_source_checkpoints(source,last_successful_announcement_at,last_successful_poll_at)
            VALUES(:source,:announcement_at,CURRENT_TIMESTAMP)
            ON DUPLICATE KEY UPDATE
                last_successful_announcement_at=CASE
                    WHEN VALUES(last_successful_announcement_at) IS NULL THEN last_successful_announcement_at
                    WHEN last_successful_announcement_at IS NULL THEN VALUES(last_successful_announcement_at)
                    ELSE GREATEST(last_successful_announcement_at,VALUES(last_successful_announcement_at))
                END,
                last_successful_poll_at=CURRENT_TIMESTAMP,consecutive_failures=0,last_error=NULL,updated_at=CURRENT_TIMESTAMP
            SQL
        );
        $statement->execute(['source' => $source, 'announcement_at' => $latest?->format('Y-m-d H:i:s')]);
    }

    public function recordFailure(string $source, string $error): void
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
            INSERT INTO government_source_checkpoints(source,consecutive_failures,last_error)
            VALUES(:source,1,:error)
            ON DUPLICATE KEY UPDATE consecutive_failures=consecutive_failures+1,last_error=VALUES(last_error),updated_at=CURRENT_TIMESTAMP
            SQL
        );
        $statement->execute(['source' => $source, 'error' => mb_substr($error, 0, 4000)]);
    }
}
