<?php

declare(strict_types=1);

namespace HalalPulse\Nse;

use HalalPulse\Support\OfficialHttpsUrl;

final class NseIntegratedUrl
{
    private const ARCHIVE_HOST = 'nsearchives.nseindia.com';

    public static function isAllowedFeed(string $url): bool
    {
        return $url === 'https://nsearchives.nseindia.com/content/RSS/Integrated_Filing_Financials.xml';
    }

    public static function isAllowedXbrl(string $url): bool
    {
        if (!OfficialHttpsUrl::isAllowed($url, [self::ARCHIVE_HOST], false)) {
            return false;
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }

        $path = (string) ($parts['path'] ?? '');

        return preg_match(
            '#^/corporate/xbrl/INTEGRATED_FILING_[A-Z0-9_]+_[0-9]+_[0-9]+_WEB\.xml$#D',
            $path,
        ) === 1;
    }

    public static function filename(string $url): string
    {
        if (!self::isAllowedXbrl($url)) {
            throw new \InvalidArgumentException('XBRL URL is outside the official NSE Integrated Filing archive.');
        }

        return basename((string) parse_url($url, PHP_URL_PATH));
    }
}
