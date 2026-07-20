<?php

declare(strict_types=1);

namespace HalalPulse\Auth;

use RuntimeException;

final class PasswordHasher
{
    public function hash(string $password): string
    {
        $algorithm = in_array('argon2id', password_algos(), true) ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
        $hash = password_hash($password, $algorithm);

        if (!is_string($hash)) {
            throw new RuntimeException('Unable to hash the password.');
        }

        return $hash;
    }

    public function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        $algorithm = in_array('argon2id', password_algos(), true) ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;

        return password_needs_rehash($hash, $algorithm);
    }
}
