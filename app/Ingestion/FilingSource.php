<?php

declare(strict_types=1);

namespace HalalPulse\Ingestion;

use DateTimeImmutable;

interface FilingSource
{
    public function exchange(): string;

    /** @return list<Filing> */
    public function fetchLatest(?DateTimeImmutable $since): array;
}
