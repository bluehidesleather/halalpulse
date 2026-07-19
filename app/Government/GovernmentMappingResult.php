<?php

declare(strict_types=1);

namespace HalalPulse\Government;

final readonly class GovernmentMappingResult
{
    /** @param list<GovernmentAnnouncement> $announcements @param list<string> $warnings */
    public function __construct(
        public array $announcements,
        public int $sourceRows,
        public array $warnings,
    ) {
    }

    public function skippedRows(): int
    {
        return max(0, $this->sourceRows - count($this->announcements));
    }
}
