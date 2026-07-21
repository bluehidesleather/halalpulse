#!/usr/bin/env php
<?php

declare(strict_types=1);

use HalalPulse\Nse\IntegratedFinancialResult;
use HalalPulse\Nse\IntegratedXbrlParser;
use HalalPulse\Sharia\NseShariaEvidenceMapper;

require dirname(__DIR__) . '/app/bootstrap.php';

$passed = 0;
$failed = 0;
$assert = static function (bool $condition, string $message) use (&$passed, &$failed): void {
    if ($condition) {
        $passed++;
        echo "[PASS] {$message}\n";
        return;
    }

    $failed++;
    echo "[FAIL] {$message}\n";
};

$fixture = file_get_contents(__DIR__ . '/fixtures/nse_integrated_xbrl.xml');
if (!is_string($fixture)) {
    throw new RuntimeException('Unable to read synthetic NSE Integrated Filing XBRL fixture.');
}

$mapper = new NseShariaEvidenceMapper();
$parsed = (new IntegratedXbrlParser())->parse($fixture);
$candidates = $mapper->map($parsed);
$assert(count($candidates) === 1, 'One conservative Sharia evidence candidate is mapped from the structured filing.');
$assert($candidates[0]['metric_key'] === 'total_revenue', 'Structured total income maps only to the total-revenue policy input.');
$assert($candidates[0]['value'] === '12605000000', 'The candidate retains the exact normalized XBRL value.');
$assert($candidates[0]['source_fact_name'] === 'Income', 'The preferred total-income source fact is retained for audit.');
$assert($candidates[0]['source_context_ref'] === 'OneD', 'The current reporting context is preferred.');
$assert($candidates[0]['confidence'] === 90, 'Direct total-income evidence receives the bounded high-confidence suggestion.');

$fallback = new IntegratedFinancialResult(
    taxonomyUri: 'https://www.sebi.gov.in/xbrl/synthetic.xsd',
    metadata: [
        'symbol' => 'FALLBACK',
        'company_name' => 'Fallback Limited',
        'period_end' => '2026-06-30',
        'currency' => 'INR',
    ],
    metrics: ['revenue_from_operations' => '500'],
    facts: [[
        'name' => 'RevenueFromOperations',
        'context_ref' => 'OneD',
        'unit_ref' => 'INR',
        'decimals' => '0',
        'value' => '500.000',
        'occurrence' => 1,
    ]],
);
$fallbackCandidates = $mapper->map($fallback);
$assert(count($fallbackCandidates) === 1, 'Revenue from operations is used only when total income is unavailable.');
$assert($fallbackCandidates[0]['value'] === '500', 'Fallback evidence is normalized without binary floating point.');
$assert($fallbackCandidates[0]['confidence'] === 75, 'The revenue-from-operations fallback receives lower confidence.');
$assert(str_contains($fallbackCandidates[0]['mapping_reason'], 'Review other income'), 'Fallback evidence explicitly requires other-income review.');

$unsafeInference = new IntegratedFinancialResult(
    taxonomyUri: 'https://www.sebi.gov.in/xbrl/synthetic.xsd',
    metadata: [
        'symbol' => 'NOINFER',
        'company_name' => 'No Inference Limited',
        'period_end' => '2026-06-30',
        'currency' => 'INR',
    ],
    metrics: ['other_income' => '25', 'debt_equity_ratio' => '0.5'],
    facts: [
        [
            'name' => 'OtherIncome',
            'context_ref' => 'OneD',
            'unit_ref' => 'INR',
            'decimals' => '0',
            'value' => '25',
            'occurrence' => 1,
        ],
        [
            'name' => 'DebtEquityRatio',
            'context_ref' => 'OneD',
            'unit_ref' => null,
            'decimals' => '2',
            'value' => '0.5',
            'occurrence' => 1,
        ],
    ],
);
$assert($mapper->map($unsafeInference) === [], 'Other income and a debt-equity ratio are never reinterpreted as prohibited income or interest-bearing debt.');

$excessPrecision = new IntegratedFinancialResult(
    taxonomyUri: 'https://www.sebi.gov.in/xbrl/synthetic.xsd',
    metadata: [
        'symbol' => 'PRECISION',
        'company_name' => 'Precision Limited',
        'period_end' => '2026-06-30',
        'currency' => 'INR',
    ],
    metrics: [],
    facts: [[
        'name' => 'Income',
        'context_ref' => 'OneD',
        'unit_ref' => 'INR',
        'decimals' => '7',
        'value' => '1.1234567',
        'occurrence' => 1,
    ]],
);
$assert($mapper->map($excessPrecision) === [], 'A candidate outside the exact DECIMAL(36,6) boundary is rejected instead of rounded.');

echo "\n{$passed} passed, {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
