<?php

declare(strict_types=1);

namespace HalalPulse\Multibagger;

final class ResearchEvidenceUrl
{
    private const FINANCIAL_HOSTS = [
        'www.nseindia.com',
        'nseindia.com',
        'nsearchives.nseindia.com',
        'archives.nseindia.com',
        'www.bseindia.com',
        'bseindia.com',
        'api.bseindia.com',
        'www.sebi.gov.in',
        'sebi.gov.in',
        'www.mca.gov.in',
        'mca.gov.in',
    ];

    private const GOVERNMENT_HOSTS = [
        'www.pib.gov.in',
        'pib.gov.in',
        'static.pib.gov.in',
        'www.sebi.gov.in',
        'sebi.gov.in',
        'investor.sebi.gov.in',
        'www.rbi.org.in',
        'rbi.org.in',
        'website.rbi.org.in',
        'www.mca.gov.in',
        'mca.gov.in',
        'www.indiabudget.gov.in',
        'indiabudget.gov.in',
    ];

    public static function isAllowed(string $url, bool $governmentOnly = false): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return false;
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        $allowed = $governmentOnly ? self::GOVERNMENT_HOSTS : array_merge(self::FINANCIAL_HOSTS, self::GOVERNMENT_HOSTS);

        return in_array($host, $allowed, true);
    }
}
