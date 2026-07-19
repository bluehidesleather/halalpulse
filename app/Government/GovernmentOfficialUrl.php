<?php

declare(strict_types=1);

namespace HalalPulse\Government;

final class GovernmentOfficialUrl
{
    /** @var array<string, list<string>> */
    private const HOSTS = [
        'PIB' => ['pib.gov.in', 'www.pib.gov.in', 'static.pib.gov.in'],
        'SEBI' => ['sebi.gov.in', 'www.sebi.gov.in', 'investor.sebi.gov.in'],
        'RBI' => ['rbi.org.in', 'www.rbi.org.in', 'website.rbi.org.in', 'rbidocs.rbi.org.in'],
        'MCA' => ['mca.gov.in', 'www.mca.gov.in'],
        'BUDGET' => ['indiabudget.gov.in', 'www.indiabudget.gov.in'],
    ];

    public static function isAllowed(string $url, string $source): bool
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
        return in_array($host, self::HOSTS[$source] ?? [], true);
    }
}
