<?php

declare(strict_types=1);

namespace HalalPulse\Ingestion;

use InvalidArgumentException;

final readonly class AnnouncementMappingResult
{
    /**
     * @param list<Filing> $filings
     * @param list<string> $warnings
     */
    public function __construct(
        public array $filings,
        public int $sourceRows,
        public array $warnings,
    ) {
        foreach ($this->filings as $filing) {
            if (!$filing instanceof Filing) {
                throw new InvalidArgumentException('Mapping result contains a non-filing item.');
            }
        }
    }

    public function skippedRows(): int
    {
        return $this->sourceRows - count($this->filings);
    }
}
