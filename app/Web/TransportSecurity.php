<?php

declare(strict_types=1);

namespace HalalPulse\Web;

final class TransportSecurity
{
    /** @param array<string, mixed> $server */
    public static function isSecure(array $server): bool
    {
        $https = strtolower(trim((string) ($server['HTTPS'] ?? '')));
        if ($https !== '' && $https !== 'off' && $https !== '0') {
            return true;
        }

        return (string) ($server['SERVER_PORT'] ?? '') === '443';
    }

    /** @param array<string, mixed> $server */
    public static function enforce(bool $forceHttps, array $server): void
    {
        if (!$forceHttps) {
            return;
        }

        if (!self::isSecure($server)) {
            http_response_code(426);
            header('Content-Type: text/plain; charset=UTF-8');
            header('Cache-Control: no-store, max-age=0');
            header('X-Content-Type-Options: nosniff');
            header('Content-Security-Policy: default-src \'none\'; frame-ancestors \'none\'');
            exit("HTTPS is required. Open this address using https:// and try again.\n");
        }

        header('Strict-Transport-Security: max-age=31536000');
    }
}
