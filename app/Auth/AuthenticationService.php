<?php

declare(strict_types=1);

namespace HalalPulse\Auth;

use HalalPulse\Support\JsonLogger;

final class AuthenticationService
{
    private ?string $dummyHash = null;

    public function __construct(
        private readonly UserRepository $users,
        private readonly LoginRateLimiter $rateLimiter,
        private readonly PasswordHasher $hasher,
        private readonly JsonLogger $logger,
        private readonly string $appKey,
        private readonly int $maxAttempts,
        private readonly int $windowSeconds,
    ) {
    }

    /** @return array{status: 'success'|'invalid'|'throttled', user: ?User} */
    public function attempt(string $email, string $password, string $ipAddress): array
    {
        $email = UserRepository::normalizeEmail($email);
        $identityHash = $this->keyedHash($email);
        $ipHash = $this->keyedHash($ipAddress);

        if ($this->rateLimiter->isBlocked($identityHash, $ipHash, $this->maxAttempts, $this->windowSeconds)) {
            $this->logger->info('Login attempt was throttled.', ['identity_hash_prefix' => substr($identityHash, 0, 12)]);

            return ['status' => 'throttled', 'user' => null];
        }

        $user = filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $this->users->findActiveByEmail($email) : null;
        $comparisonHash = $user?->passwordHash ?? $this->dummyHash();
        $valid = $this->hasher->verify($password, $comparisonHash);

        if ($user === null || !$valid) {
            $this->rateLimiter->recordFailure($identityHash, $ipHash, $user?->id);
            $this->logger->info('Login attempt failed.', ['identity_hash_prefix' => substr($identityHash, 0, 12)]);

            return ['status' => 'invalid', 'user' => null];
        }

        if ($this->hasher->needsRehash($user->passwordHash)) {
            $this->users->rehashPasswordHash($user->id, $this->hasher->hash($password));
        }

        $this->rateLimiter->recordSuccess($identityHash, $ipHash, $user->id);
        $this->users->recordSuccessfulLogin($user->id);
        $this->logger->info('Login succeeded.', ['user_id' => $user->id]);

        return ['status' => 'success', 'user' => $user];
    }

    public function changePassword(User $user, string $currentPassword, string $newPassword): bool
    {
        if (!$this->hasher->verify($currentPassword, $user->passwordHash)) {
            $this->logger->info('Password change rejected.', ['user_id' => $user->id]);
            return false;
        }

        $this->users->updatePasswordHash($user->id, $this->hasher->hash($newPassword));
        $this->logger->info('Password changed and older sessions revoked.', ['user_id' => $user->id]);

        return true;
    }

    private function keyedHash(string $value): string
    {
        return hash_hmac('sha256', $value, $this->appKey);
    }

    private function dummyHash(): string
    {
        $this->dummyHash ??= $this->hasher->hash(bin2hex(random_bytes(24)));

        return $this->dummyHash;
    }
}
