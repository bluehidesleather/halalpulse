<?php

declare(strict_types=1);

namespace HalalPulse\Nse;

use InvalidArgumentException;

final readonly class IntegratedFinancialResult
{
    /**
     * @param array<string, string|null> $metadata
     * @param array<string, string|null> $metrics
     * @param list<array{name: string, context_ref: string, unit_ref: ?string, decimals: ?string, value: string, occurrence: int}> $facts
     */
    public function __construct(
        public string $taxonomyUri,
        public array $metadata,
        public array $metrics,
        public array $facts,
    ) {
        foreach (['symbol', 'company_name', 'period_end'] as $required) {
            if (trim((string) ($this->metadata[$required] ?? '')) === '') {
                throw new InvalidArgumentException("Integrated XBRL metadata is missing {$required}.");
            }
        }
    }
}
