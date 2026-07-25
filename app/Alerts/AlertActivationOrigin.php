<?php

declare(strict_types=1);

namespace HalalPulse\Alerts;

use InvalidArgumentException;

final class AlertActivationOrigin
{
    public static function assertPermanent(string $origin): void
    {
        $origin = rtrim($origin, '/');
        if ($origin === '' || strlen($origin) > 2048 || preg_match('/[\x00-\x20\x7f]/', $origin) === 1) {
            throw new InvalidArgumentException('Alert application origin is invalid.');
        }

        $parts = parse_url($origin);
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
        $path = is_array($parts) ? (string) ($parts['path'] ?? '') : '';
        if (!is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || $host === ''
            || filter_var($host, FILTER_VALIDATE_IP) !== false
            || preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))+$/D', $host) !== 1
            || ($path !== '' && $path !== '/')
            || isset($parts['query'])
            || isset($parts['fragment'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['port'])) {
            throw new InvalidArgumentException('Alert application origin must be a public HTTPS DNS origin without credentials, a custom port, path, query, or fragment.');
        }
        if ($host === 'halalpulse.example' || str_ends_with($host, '.hostingersite.com')) {
            throw new InvalidArgumentException('A permanent HTTPS domain is required before Telegram preparation.');
        }
    }
}
