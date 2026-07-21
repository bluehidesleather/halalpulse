<?php

declare(strict_types=1);

namespace HalalPulse\Government;

use HalalPulse\Support\OfficialHttpsUrl;

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
        return OfficialHttpsUrl::isAllowed($url, self::HOSTS[$source] ?? []);
    }
}
