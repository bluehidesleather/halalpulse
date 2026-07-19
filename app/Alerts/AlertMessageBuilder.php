<?php

declare(strict_types=1);

namespace HalalPulse\Alerts;

use InvalidArgumentException;

final readonly class AlertMessageBuilder
{
    public function __construct(private string $appBaseUrl)
    {
    }

    /** @param array<string, mixed> $candidate */
    public function build(array $candidate): string
    {
        $score = (int) ($candidate['final_score'] ?? 0);
        $companyId = (int) ($candidate['company_id'] ?? 0);
        if ($score < 1 || $score > 4 || $companyId < 1) {
            throw new InvalidArgumentException('Alert candidate is outside the score gate.');
        }
        $symbol = trim((string) ($candidate['symbol'] ?? ''));
        $exchange = trim((string) ($candidate['exchange'] ?? ''));
        $companyName = trim((string) ($candidate['company_name'] ?? ''));
        $period = trim((string) ($candidate['period_end'] ?? ''));
        if ($symbol === '' || $exchange === '' || $companyName === '' || $period === '') {
            throw new InvalidArgumentException('Alert candidate identity is incomplete.');
        }
        $valuation = (int) ($candidate['undervalued_by_both'] ?? 0) === 1 ? 'Graham + DCF both agree' : 'No dual-value agreement';
        $url = rtrim($this->appBaseUrl, '/') . '/multibagger-company.php?id=' . $companyId . '&period=' . rawurlencode($period);
        return implode("\n", [
            'HalalPulse research alert',
            "{$exchange}:{$symbol} — {$companyName}",
            "Sharia: current active-policy pass ({$period})",
            "Potential score: {$score}/10 (1 strongest)",
            "Valuation: {$valuation}",
            "Review: {$url}",
            'Personal research only — not financial or religious advice; returns are not guaranteed.',
        ]);
    }
}
