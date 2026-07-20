<?php

declare(strict_types=1);

namespace HalalPulse\Multibagger;

final readonly class MultibaggerScoringResult
{
    /** @param list<array<string, mixed>> $factorResults @param list<string> $reasons @param array<string, mixed> $valuationSnapshot @param array<string, mixed> $riskSnapshot */
    public function __construct(
        public string $status,
        public ?int $finalScore,
        public ?string $weightedScore,
        public bool $undervaluedByBoth,
        public bool $alertEligible,
        public string $marketCapCategory,
        public array $factorResults,
        public array $reasons,
        public array $valuationSnapshot,
        public array $riskSnapshot,
    ) {
    }
}
