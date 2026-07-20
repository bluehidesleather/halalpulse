<?php

declare(strict_types=1);

namespace HalalPulse\Auth;

final readonly class User
{
    public function __construct(
        public int $id,
        public string $email,
        public string $displayName,
        public string $passwordHash,
        public string $role,
        public bool $isActive,
    ) {
    }
}
