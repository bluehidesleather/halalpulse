<?php

declare(strict_types=1);

namespace HalalPulse\Alerts;

use InvalidArgumentException;
use PDO;
use PDOException;
use Throwable;

final readonly class AlertRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return list<array<string, mixed>> */
    public function eligibleCandidates(string $channel, string $recipientHash, int $limit): array
    {
        if ($channel !== 'telegram') {
            throw new InvalidArgumentException('Only Telegram delivery is enabled.');
        }
        $limit = max(1, min(5, $limit));
        $statement = $this->pdo->prepare($this->candidateSql(
            "AND NOT EXISTS(SELECT 1 FROM alert_deliveries ad WHERE ad.score_id=ms.id AND ad.channel=:channel AND ad.recipient_hash=:recipient_hash)"
        ) . ' ORDER BY ms.computed_at,ms.id LIMIT ' . $limit);
        $statement->execute(['channel' => $channel, 'recipient_hash' => $recipientHash]);
        return $statement->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function currentCandidateByScore(int $scoreId): ?array
    {
        $statement = $this->pdo->prepare($this->candidateSql('AND ms.id=:score_id') . ' LIMIT 1');
        $statement->execute(['score_id' => $scoreId]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    public function reserve(int $scoreId, int $recipientId, string $channel, string $recipientHash, string $messageHash): ?AlertReservation
    {
        if ($recipientId < 1 || $channel !== 'telegram') {
            throw new InvalidArgumentException('A valid active Telegram recipient is required.');
        }
        $this->pdo->beginTransaction();
        try {
            $insert = $this->pdo->prepare(
                <<<'SQL'
                INSERT INTO alert_deliveries(score_id,recipient_id,channel,recipient_hash,status,message_hash,attempt_count,reserved_at,last_attempt_at)
                SELECT :score_id,ar.id,'telegram',:recipient_hash,'reserved',:message_hash,1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP
                FROM alert_recipients ar
                WHERE ar.id=:recipient_id AND ar.channel='telegram' AND ar.is_active=1 AND ar.recipient_hash=:recipient_hash_check
                SQL
            );
            try {
                $insert->execute([
                    'score_id' => $scoreId,
                    'recipient_id' => $recipientId,
                    'recipient_hash' => $recipientHash,
                    'recipient_hash_check' => $recipientHash,
                    'message_hash' => $messageHash,
                ]);
                if ($insert->rowCount() !== 1) {
                    throw new InvalidArgumentException('The Telegram recipient is no longer active.');
                }
            } catch (PDOException $exception) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                if ((int) ($exception->errorInfo[1] ?? 0) === 1062) {
                    return null;
                }
                throw $exception;
            }
            $deliveryId = (int) $this->pdo->lastInsertId();
            $attemptId = $this->insertAttempt($deliveryId, 1);
            $this->pdo->commit();
            return new AlertReservation($deliveryId, $attemptId, 1);
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function recoverStaleReservations(): int
    {
        $this->pdo->beginTransaction();
        try {
            $attempts = $this->pdo->prepare(
                <<<'SQL'
                UPDATE alert_delivery_attempts ada
                INNER JOIN alert_deliveries ad ON ad.id=ada.delivery_id
                SET ada.result='unknown',ada.error_message='Process ended before provider acceptance was recorded.',ada.finished_at=CURRENT_TIMESTAMP
                WHERE ad.status='reserved' AND ad.last_attempt_at<DATE_SUB(CURRENT_TIMESTAMP,INTERVAL 10 MINUTE) AND ada.result='running'
                SQL
            );
            $attempts->execute();
            $deliveries = $this->pdo->prepare(
                <<<'SQL'
                UPDATE alert_deliveries
                SET status='unknown',error_message='Process ended before provider acceptance was recorded.'
                WHERE status='reserved' AND last_attempt_at<DATE_SUB(CURRENT_TIMESTAMP,INTERVAL 10 MINUTE)
                SQL
            );
            $deliveries->execute();
            $count = $deliveries->rowCount();
            $this->pdo->commit();
            return $count;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @return array<string, mixed>|null */
    public function delivery(int $deliveryId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM alert_deliveries WHERE id=:id LIMIT 1');
        $statement->execute(['id' => $deliveryId]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    public function beginManualRetry(int $deliveryId, string $messageHash): AlertReservation
    {
        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare('SELECT * FROM alert_deliveries WHERE id=:id FOR UPDATE');
            $lock->execute(['id' => $deliveryId]);
            $row = $lock->fetch();
            if (!is_array($row)) {
                throw new InvalidArgumentException('Alert delivery was not found.');
            }
            if (!in_array((string) $row['status'], ['failed', 'unknown'], true)) {
                throw new InvalidArgumentException('Only failed or unknown deliveries can be manually retried.');
            }
            if (!hash_equals((string) $row['message_hash'], $messageHash)) {
                throw new InvalidArgumentException('The alert content changed; do not retry an altered message under the same idempotency record.');
            }
            $attemptNumber = (int) $row['attempt_count'] + 1;
            if ($attemptNumber > 5) {
                throw new InvalidArgumentException('The manual alert retry limit has been reached.');
            }
            $update = $this->pdo->prepare("UPDATE alert_deliveries SET status='reserved',attempt_count=:attempt_count,last_attempt_at=CURRENT_TIMESTAMP,error_code=NULL,error_message=NULL WHERE id=:id");
            $update->execute(['attempt_count' => $attemptNumber, 'id' => $deliveryId]);
            $attemptId = $this->insertAttempt($deliveryId, $attemptNumber);
            $this->pdo->commit();
            return new AlertReservation($deliveryId, $attemptId, $attemptNumber);
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function markAccepted(AlertReservation $reservation, ProviderMessageResult $result): void
    {
        $this->pdo->beginTransaction();
        try {
            $delivery = $this->pdo->prepare("UPDATE alert_deliveries SET status='accepted',provider_message_id=:message_id,provider_status=:provider_status,submitted_at=CURRENT_TIMESTAMP,error_code=NULL,error_message=NULL WHERE id=:id AND status='reserved'");
            $delivery->execute(['message_id' => $result->messageId, 'provider_status' => $result->status, 'id' => $reservation->deliveryId]);
            if ($delivery->rowCount() !== 1) {
                throw new InvalidArgumentException('Alert delivery reservation changed before completion.');
            }
            $attempt = $this->pdo->prepare("UPDATE alert_delivery_attempts SET result='accepted',provider_message_id=:message_id,provider_status=:provider_status,finished_at=CURRENT_TIMESTAMP WHERE id=:id AND result='running'");
            $attempt->execute(['message_id' => $result->messageId, 'provider_status' => $result->status, 'id' => $reservation->attemptId]);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function markFailure(AlertReservation $reservation, bool $unknown, ?string $errorCode, string $errorMessage): void
    {
        $status = $unknown ? 'unknown' : 'failed';
        $this->pdo->beginTransaction();
        try {
            $delivery = $this->pdo->prepare("UPDATE alert_deliveries SET status=:status,error_code=:error_code,error_message=:error_message WHERE id=:id AND status='reserved'");
            $delivery->execute(['status' => $status, 'error_code' => $errorCode, 'error_message' => mb_substr($errorMessage, 0, 1000), 'id' => $reservation->deliveryId]);
            $attempt = $this->pdo->prepare("UPDATE alert_delivery_attempts SET result=:status,error_code=:error_code,error_message=:error_message,finished_at=CURRENT_TIMESTAMP WHERE id=:id AND result='running'");
            $attempt->execute(['status' => $status, 'error_code' => $errorCode, 'error_message' => mb_substr($errorMessage, 0, 1000), 'id' => $reservation->attemptId]);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @return array{eligible:int,accepted:int,failed:int,unknown:int} */
    public function summary(): array
    {
        $row = $this->pdo->query(
            <<<'SQL'
            SELECT
                (SELECT COUNT(*) FROM multibagger_scores ms WHERE ms.alert_eligible=1 AND ms.id=(SELECT MAX(ms2.id) FROM multibagger_scores ms2 WHERE ms2.company_id=ms.company_id)) AS eligible,
                (SELECT COUNT(*) FROM alert_deliveries WHERE status='accepted') AS accepted,
                (SELECT COUNT(*) FROM alert_deliveries WHERE status='failed') AS failed,
                (SELECT COUNT(*) FROM alert_deliveries WHERE status='unknown') AS unknown
            SQL
        )->fetch();
        return ['eligible' => (int)($row['eligible']??0), 'accepted' => (int)($row['accepted']??0), 'failed' => (int)($row['failed']??0), 'unknown' => (int)($row['unknown']??0)];
    }

    /** @return list<array<string, mixed>> */
    public function recentDeliveries(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        return $this->pdo->query(
            <<<'SQL'
            SELECT ad.*,ms.final_score,ms.period_end,c.exchange,c.symbol,c.company_name,ar.label AS recipient_label
            FROM alert_deliveries ad
            INNER JOIN multibagger_scores ms ON ms.id=ad.score_id
            INNER JOIN companies c ON c.id=ms.company_id
            LEFT JOIN alert_recipients ar ON ar.id=ad.recipient_id
            ORDER BY ad.id DESC LIMIT
            SQL . ' ' . $limit
        )->fetchAll();
    }

    private function insertAttempt(int $deliveryId, int $number): int
    {
        $statement = $this->pdo->prepare("INSERT INTO alert_delivery_attempts(delivery_id,attempt_number,result,started_at) VALUES(:delivery_id,:attempt_number,'running',CURRENT_TIMESTAMP)");
        $statement->execute(['delivery_id' => $deliveryId, 'attempt_number' => $number]);
        return (int) $this->pdo->lastInsertId();
    }

    private function candidateSql(string $extraWhere): string
    {
        return <<<'SQL'
            SELECT ms.id AS score_id,ms.company_id,ms.final_score,ms.period_end,ms.undervalued_by_both,ms.computed_at,
                c.exchange,c.symbol,c.company_name,sp.version AS sharia_policy_version,mm.version AS methodology_version
            FROM multibagger_scores ms
            INNER JOIN companies c ON c.id=ms.company_id AND c.is_active=1
            INNER JOIN multibagger_methodologies mm ON mm.id=ms.methodology_id AND mm.is_active=1
            INNER JOIN sharia_screenings ss ON ss.id=ms.sharia_screening_id AND ss.status='passed' AND ss.period_end=ms.period_end
            INNER JOIN sharia_policies sp ON sp.id=ss.policy_id AND sp.is_active=1
            WHERE ms.alert_eligible=1
              AND ms.status='scored'
              AND ms.final_score BETWEEN 1 AND 4
              AND ms.id=(SELECT MAX(ms_latest.id) FROM multibagger_scores ms_latest WHERE ms_latest.company_id=ms.company_id)
              AND ss.id=(SELECT MAX(ss_latest.id) FROM sharia_screenings ss_latest WHERE ss_latest.company_id=ms.company_id AND ss_latest.period_end=ms.period_end)
              AND NOT EXISTS(SELECT 1 FROM sharia_financial_inputs sfi WHERE sfi.company_id=ms.company_id AND sfi.period_end=ms.period_end AND sfi.evidence_status='current' AND sfi.accepted_at>ss.computed_at)
              AND NOT EXISTS(SELECT 1 FROM company_sharia_activity_reviews csar WHERE csar.company_id=ms.company_id AND csar.reviewed_at>ss.computed_at)
              AND NOT EXISTS(SELECT 1 FROM multibagger_factor_reviews changed_factor WHERE changed_factor.company_id=ms.company_id AND changed_factor.period_end=ms.period_end AND changed_factor.review_status='current' AND changed_factor.reviewed_at>ms.computed_at)
              AND NOT EXISTS(SELECT 1 FROM multibagger_valuation_reviews changed_valuation WHERE changed_valuation.company_id=ms.company_id AND changed_valuation.period_end=ms.period_end AND changed_valuation.review_status='current' AND changed_valuation.reviewed_at>ms.computed_at)
              AND NOT EXISTS(SELECT 1 FROM multibagger_risk_reviews changed_risk WHERE changed_risk.company_id=ms.company_id AND changed_risk.period_end=ms.period_end AND changed_risk.review_status='current' AND changed_risk.reviewed_at>ms.computed_at)
              AND EXISTS(
                  SELECT 1 FROM multibagger_factor_reviews mfr
                  INNER JOIN government_tailwind_reviews gtr ON gtr.id=mfr.government_tailwind_review_id AND gtr.review_status='current' AND gtr.impact IN('strong_tailwind','moderate_tailwind')
                  WHERE mfr.company_id=ms.company_id AND mfr.period_end=ms.period_end AND mfr.factor_key='macro_tailwind' AND mfr.review_status='current'
              )
            SQL . "\n" . $extraWhere;
    }
}
