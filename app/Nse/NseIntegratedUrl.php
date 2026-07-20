<?php

declare(strict_types=1);

namespace HalalPulse\Nse;

final class NseIntegratedUrl
{
    public static function isAllowedFeed(string $url): bool
    {
        return $url === 'https://nsearchives.nseindia.com/content/RSS/Integrated_Filing_Financials.xml';
    }

    public static function isAllowedXbrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }

        if (strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || strtolower((string) ($parts['host'] ?? '')) !== 'nsearchives.nseindia.com'
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
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
