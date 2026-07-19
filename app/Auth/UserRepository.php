<?php

declare(strict_types=1);

namespace HalalPulse\Auth;

use PDO;
use RuntimeException;

final class UserRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function findActiveByEmail(string $email): ?User
    {
        $statement = $this->pdo->prepare(
            'SELECT id, email, display_name, password_hash, role, is_active FROM users WHERE email = :email AND is_active = 1 LIMIT 1'
        );
        $statement->execute(['email' => self::normalizeEmail($email)]);
        $row = $statement->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function findActiveById(int $id): ?User
    {
        $statement = $this->pdo->prepare(
            'SELECT id, email, display_name, password_hash, role, is_active FROM users WHERE id = :id AND is_active = 1 LIMIT 1'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function emailExists(string $email): bool
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM users WHERE email = :email');
        $statement->execute(['email' => self::normalizeEmail($email)]);

        return (int) $statement->fetchColumn() > 0;
    }

    public function activeAdminCount(): int
    {
        return (int) $this->pdo->query(
            "SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1"
        )->fetchColumn();
    }

    public function createAdmin(string $email, string $displayName, string $passwordHash): int
    {
        $email = self::normalizeEmail($email);
        $displayName = trim($displayName);

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 191) {
            throw new RuntimeException('A valid email address is required.');
        }

        if ($displayName === '' || mb_strlen($displayName) > 100) {
            throw new RuntimeException('Display name must contain 1 to 100 characters.');
        }

        $statement = $this->pdo->prepare(
            <<<'SQL'
            INSERT INTO users (email, display_name, password_hash, role, is_active)
            VALUES (:email, :display_name, :password_hash, 'admin', 1)
            SQL
        );
        $statement->execute([
            'email' => $email,
            'display_name' => $displayName,
            'password_hash' => $passwordHash,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function updatePasswordHash(int $userId, string $passwordHash): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE users SET password_hash = :password_hash, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        );
        $statement->execute(['password_hash' => $passwordHash, 'id' => $userId]);
    }

    public function recordSuccessfulLogin(int $userId): void
    {
        $statement = $this->pdo->prepare('UPDATE users SET last_login_at = CURRENT_TIMESTAMP WHERE id = :id');
        $statement->execute(['id' => $userId]);
    }

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email), 'UTF-8');
    }

    private function hydrate(array $row): User
    {
        return new User(
            id: (int) $row['id'],
            email: (string) $row['email'],
            displayName: (string) $row['display_name'],
            passwordHash: (string) $row['password_hash'],
            role: (string) $row['role'],
            isActive: (bool) $row['is_active'],
        );
    }
}
