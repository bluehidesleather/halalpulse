<?php

declare(strict_types=1);

namespace HalalPulse\Support;

final class OfficialUrl
{
    /** @param list<string> $allowedHosts */
    public static function attachment(?string $value, string $baseUrl, array $allowedHosts): ?string
    {
        $value = (string) $value;
        if ($value === '' || strlen($value) > 4096 || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (str_starts_with($value, '//')) {
            $value = 'https:' . $value;
        } elseif (parse_url($value, PHP_URL_SCHEME) === null) {
            $value = rtrim($baseUrl, '/') . '/' . ltrim($value, '/');
        }

        $value = str_replace(' ', '%20', $value);

        return OfficialHttpsUrl::isAllowed($value, $allowedHosts) ? $value : null;
    }
}
