<?php

declare(strict_types=1);

namespace HalalPulse\Government;

final readonly class GovernmentClassification
{
    public function __construct(
        public ?string $sector,
        public string $suggestedImpact,
        public int $confidence,
        public string $reason,
    ) {
    }
}
