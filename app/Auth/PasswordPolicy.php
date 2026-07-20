<?php

declare(strict_types=1);

namespace HalalPulse\Auth;

final class PasswordPolicy
{
    public const MIN_LENGTH = 12;
    public const MAX_LENGTH = 128;

    /** @return list<string> */
    public function violations(string $password): array
    {
        $length = mb_strlen($password, 'UTF-8');
        $violations = [];

        if ($length < self::MIN_LENGTH) {
            $violations[] = 'Password must contain at least 12 characters.';
        }

        if ($length > self::MAX_LENGTH) {
            $violations[] = 'Password must contain no more than 128 characters.';
        }

        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $password) === 1) {
            $violations[] = 'Password contains unsupported control characters.';
        }

        return $violations;
    }

    public function isValid(string $password): bool
    {
        return $this->violations($password) === [];
    }
}
