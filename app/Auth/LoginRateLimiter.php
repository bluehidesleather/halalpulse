<?php

declare(strict_types=1);

namespace HalalPulse\Auth;

use DateTimeImmutable;
use PDO;

final class LoginRateLimiter
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function isBlocked(string $identityHash, string $ipHash, int $maxAttempts, int $windowSeconds): bool
    {
        $cutoff = (new DateTimeImmutable())->modify('-' . max(1, $windowSeconds) . ' seconds');
        $statement = $this->pdo->prepare(
            <<<'SQL'
            SELECT COUNT(*)
            FROM login_attempts
            WHERE was_successful = 0
              AND attempted_at >= :cutoff
              AND (identity_hash = :identity_hash OR ip_hash = :ip_hash)
            SQL
        );
        $statement->execute([
            'cutoff' => $cutoff->format('Y-m-d H:i:s'),
            'identity_hash' => $identityHash,
            'ip_hash' => $ipHash,
        ]);

        return (int) $statement->fetchColumn() >= max(1, $maxAttempts);
    }

    public function recordFailure(string $identityHash, string $ipHash, ?int $userId): void
    {
        $this->insertAttempt($identityHash, $ipHash, $userId, false);
    }

    public function recordSuccess(string $identityHash, string $ipHash, int $userId): void
    {
        $this->insertAttempt($identityHash, $ipHash, $userId, true);

        $statement = $this->pdo->prepare(
            'DELETE FROM login_attempts WHERE was_successful = 0 AND identity_hash = :identity_hash'
        );
        $statement->execute(['identity_hash' => $identityHash]);
    }

    private function insertAttempt(string $identityHash, string $ipHash, ?int $userId, bool $successful): void
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
            INSERT INTO login_attempts (identity_hash, ip_hash, user_id, was_successful)
            VALUES (:identity_hash, :ip_hash, :user_id, :was_successful)
            SQL
        );
        $statement->execute([
            'identity_hash' => $identityHash,
            'ip_hash' => $ipHash,
            'user_id' => $userId,
            'was_successful' => $successful ? 1 : 0,
        ]);
    }
}
