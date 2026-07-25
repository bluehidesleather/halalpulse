<?php

declare(strict_types=1);

namespace HalalPulse\Alerts;

use InvalidArgumentException;

final class AlertActivationOrigin
{
    public static function assertPermanent(string $origin): void
    {
        $origin = rtrim($origin, '/');
        $parts = parse_url($origin);
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
        if ($host === '' || $host === 'halalpulse.example' || str_ends_with($host, '.hostingersite.com')) {
            throw new InvalidArgumentException('A permanent HTTPS domain is required before Telegram preparation.');
        }
    }
}
