<?php

declare(strict_types=1);

namespace HalalPulse\Government;

use DateTimeImmutable;

interface GovernmentSource
{
    public function source(): string;

    /** @return list<GovernmentAnnouncement> */
    public function fetchLatest(?DateTimeImmutable $checkpoint): array;
}
