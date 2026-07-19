<?php

declare(strict_types=1);

namespace HalalPulse\Support;

final class OfficialUrl
{
    /** @param list<string> $allowedHosts */
    public static function attachment(?string $value, string $baseUrl, array $allowedHosts): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        if (str_starts_with($value, '//')) {
            $value = 'https:' . $value;
        } elseif (parse_url($value, PHP_URL_SCHEME) === null) {
            $value = rtrim($baseUrl, '/') . '/' . ltrim($value, '/');
        }

        $value = str_replace(' ', '%20', $value);
        $parts = parse_url($value);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');
        $allowed = array_map(static fn (string $item): string => strtolower($item), $allowedHosts);

        if (
            $scheme !== 'https'
            || $host === ''
            || !in_array($host, $allowed, true)
            || str_contains($path, '..')
            || filter_var($value, FILTER_VALIDATE_URL) === false
        ) {
            return null;
        }

        return $value;
    }
}
