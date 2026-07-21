<?php

declare(strict_types=1);

namespace HalalPulse\Support;

use HalalPulse\Http\HttpRequestPolicy;
use RuntimeException;

final class OfficialHttpsUrl
{
    /** @param list<string> $allowedHosts */
    public static function isAllowed(string $url, array $allowedHosts, bool $allowQuery = true): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        try {
            (new HttpRequestPolicy($allowedHosts))->assertAllowedUrl($url);
        } catch (RuntimeException) {
            return false;
        }

        $parts = parse_url($url);
        if (!is_array($parts) || (!$allowQuery && isset($parts['query']))) {
            return false;
        }

        return !self::hasUnsafePath((string) ($parts['path'] ?? ''));
    }

    public static function hasUnsafePath(string $path): bool
    {
        if (str_contains($path, '\\')) {
            return true;
        }

        $decoded = $path;
        for ($pass = 0; $pass < 3; $pass++) {
            $next = rawurldecode($decoded);
            if ($next === $decoded) {
                break;
            }
            $decoded = $next;
        }

        if (preg_match('/[\x00-\x1f\x7f]/', $decoded) === 1 || str_contains($decoded, '\\')) {
            return true;
        }

        foreach (explode('/', $decoded) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return true;
            }
        }

        return false;
    }
}
