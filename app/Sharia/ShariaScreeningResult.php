<?php

declare(strict_types=1);

namespace HalalPulse\Sharia;

final readonly class ShariaScreeningResult
{
    /**
     * @param list<array<string, bool|string>> $ratioResults
     * @param list<string> $reasons
     * @param array<string, array<string, int|string|null>> $inputSnapshot
     */
    public function __construct(
        public string $status,
        public ?int $complianceRank,
        public string $activityStatus,
        public array $ratioResults,
        public array $reasons,
        public array $inputSnapshot,
    ) {
    }
}
