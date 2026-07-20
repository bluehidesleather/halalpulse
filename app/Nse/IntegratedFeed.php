<?php

declare(strict_types=1);

namespace HalalPulse\Nse;

use DateTimeImmutable;

final readonly class IntegratedFeed
{
    /**
     * @param list<IntegratedFeedItem> $items
     * @param list<string> $warnings
     */
    public function __construct(
        public string $title,
        public DateTimeImmutable $lastBuildAt,
        public int $ttlMinutes,
        public int $sourceRows,
        public array $items,
        public array $warnings = [],
    ) {
    }
}
