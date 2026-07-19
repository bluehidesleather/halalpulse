<?php

declare(strict_types=1);

namespace HalalPulse\Documents;

final readonly class MetricCandidate
{
    public function __construct(
        public string $metricKey,
        public string $statementScope,
        public string $periodLabel,
        public string $currentValue,
        public ?string $comparisonValue,
        public ?string $currency,
        public string $scaleLabel,
        public int $confidence,
        public string $evidenceSnippet,
    ) {
    }
}
