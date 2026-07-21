<?php

declare(strict_types=1);

namespace HalalPulse\Auth;

final readonly class SessionSecurityPolicy
{
    public function __construct(
        private int $idleSeconds,
        private int $absoluteSeconds,
        private int $rotationSeconds,
    ) {
    }

    /**
     * @param array<string, mixed>|null $auth
     * @return array{authenticated:bool,expired:bool,rotate:bool,reason:?string,evaluated_at:int}
     */
    public function evaluate(?array $auth, ?int $now = null): array
    {
        $now ??= time();
        if ($auth === null) {
            return [
                'authenticated' => false,
                'expired' => false,
                'rotate' => false,
                'reason' => null,
                'evaluated_at' => $now,
            ];
        }

        $createdAt = (int) ($auth['created_at'] ?? 0);
        $lastActivityAt = (int) ($auth['last_activity_at'] ?? 0);
        $lastRegeneratedAt = (int) ($auth['last_regenerated_at'] ?? $createdAt);
        if ($createdAt <= 0 || $lastActivityAt <= 0 || $createdAt > $now || $lastActivityAt > $now) {
            return [
                'authenticated' => true,
                'expired' => true,
                'rotate' => false,
                'reason' => 'invalid_timestamps',
                'evaluated_at' => $now,
            ];
        }

        if ($this->idleSeconds > 0 && ($now - $lastActivityAt) > $this->idleSeconds) {
            return [
                'authenticated' => true,
                'expired' => true,
                'rotate' => false,
                'reason' => 'idle_timeout',
                'evaluated_at' => $now,
            ];
        }

        if ($this->absoluteSeconds > 0 && ($now - $createdAt) > $this->absoluteSeconds) {
            return [
                'authenticated' => true,
                'expired' => true,
                'rotate' => false,
                'reason' => 'absolute_timeout',
                'evaluated_at' => $now,
            ];
        }

        $rotate = $this->rotationSeconds > 0
            && ($lastRegeneratedAt <= 0 || $lastRegeneratedAt > $now || ($now - $lastRegeneratedAt) >= $this->rotationSeconds);

        return [
            'authenticated' => true,
            'expired' => false,
            'rotate' => $rotate,
            'reason' => null,
            'evaluated_at' => $now,
        ];
    }
}
