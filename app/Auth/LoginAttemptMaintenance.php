<?php

declare(strict_types=1);

namespace HalalPulse\Auth;

use DateTimeImmutable;
use PDO;
use RuntimeException;

final readonly class LoginAttemptMaintenance
{
    public function __construct(private PDO $pdo)
    {
    }

    public function pruneBefore(DateTimeImmutable $cutoff, int $maximumRows): int
    {
        if ($maximumRows < 1 || $maximumRows > 10000) {
            throw new RuntimeException('Login-attempt prune limit must be between 1 and 10000 rows.');
        }

        $statement = $this->pdo->prepare(
            <<<'SQL'
            DELETE FROM login_attempts
            WHERE attempted_at < :cutoff
            ORDER BY attempted_at ASC, id ASC
            LIMIT %d
            SQL
        );
        $sql = sprintf((string) $statement->queryString, $maximumRows);
        $statement = $this->pdo->prepare($sql);
        $statement->execute(['cutoff' => $cutoff->format('Y-m-d H:i:s')]);

        return $statement->rowCount();
    }
}
