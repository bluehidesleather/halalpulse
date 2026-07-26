#!/usr/bin/env php
<?php

declare(strict_types=1);

use HalalPulse\Sharia\DecimalMath;
use HalalPulse\Sharia\ShariaPolicy;
use HalalPulse\Sharia\ShariaPolicyValidator;
use HalalPulse\Sharia\ShariaScreeningEngine;

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

$json = file_get_contents(dirname(__DIR__) . '/config/sharia-research-policy.json');
if (!is_string($json)) {
    throw new RuntimeException('Unable to read the versioned research policy.');
}
$payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
if (!is_array($payload)) {
    throw new RuntimeException('Research policy JSON object expected.');
}
$payload['approved_for_use'] = true;

$validator = new ShariaPolicyValidator();
$validated = $validator->validate($payload);
$policy = new ShariaPolicy(
    id: 1,
    version: $validated['version'],
    name: $validated['name'],
    authorityName: $validated['authority_name'],
    authorityStandard: $validated['authority_standard'],
    authorityReferenceUrl: $validated['authority_reference_url'],
    effectiveDate: $validated['effective_date'],
    verifiedBy: $validated['verified_by'],
    verificationNote: $validated['verification_note'],
    policyHash: $validator->hash($payload),
    isActive: true,
    ratios: $validated['ratios'],
    assuranceLevel: $validated['assurance_level'],
    disclaimer: $validated['disclaimer'],
);
$assert($policy->isResearch(), 'The versioned strict policy is explicitly research assurance.');
$assert($policy->statusLabel('passed') === 'Research pass', 'A passing database status is presented as Research pass.');
$assert($policy->statusLabel('failed') === 'Research fail', 'A failing database status is presented as Research fail.');

$input = static fn (string $value, string $scale = 'crore'): array => [
    'value' => $value,
    'currency' => 'INR',
    'scale_label' => $scale,
    'source_document_id' => 1,
    'evidence_note' => 'Synthetic primary-evidence value for strict research-policy testing.',
];
$base = [
    'interest_bearing_debt' => $input('20'),
    'market_capitalization' => $input('100'),
    'interest_bearing_deposits' => $input('10'),
    'impermissible_income' => $input('4'),
    'total_income' => $input('100'),
    'eligible_real_operating_assets' => $input('30'),
    'total_assets' => $input('100'),
];
$engine = new ShariaScreeningEngine(new DecimalMath());

$boundary = $engine->screen($policy, 'permissible', $base);
$assert($boundary->status === 'passed', 'Exactly 30 percent eligible real operating assets passes the strict minimum.');
$assert($boundary->complianceRank === 5, 'A result exactly on the strict asset minimum receives rank 5.');
$assetResult = array_values(array_filter($boundary->ratioResults, static fn (array $row): bool => $row['key'] === 'asset_substance_ratio'))[0] ?? null;
$assert(is_array($assetResult) && $assetResult['comparison'] === 'minimum' && $assetResult['percentage'] === '30', 'The immutable ratio result preserves the minimum direction and exact percentage.');

$strongAssets = $base;
$strongAssets['eligible_real_operating_assets'] = $input('60');
$strong = $engine->screen($policy, 'permissible', $strongAssets);
$assert($strong->status === 'passed' && $strong->complianceRank === 3, 'Stronger asset substance improves utilization while the 80 percent impermissible-income utilization correctly determines rank 3.');

$belowMinimum = $base;
$belowMinimum['eligible_real_operating_assets'] = $input('29.999999');
$failedAsset = $engine->screen($policy, 'permissible', $belowMinimum);
$assert($failedAsset->status === 'failed' && $failedAsset->complianceRank === null, 'A value just below the 30 percent asset minimum fails with no rank.');
$assert(str_contains(implode(' ', $failedAsset->reasons), 'below the active policy minimum'), 'The failure reason identifies the minimum-threshold breach.');

$tooMuchDebt = $base;
$tooMuchDebt['interest_bearing_debt'] = $input('30.000001');
$assert($engine->screen($policy, 'permissible', $tooMuchDebt)->status === 'failed', 'A value just above the 30 percent debt maximum fails.');

$missingTotalAssets = $base;
unset($missingTotalAssets['total_assets']);
$assert($engine->screen($policy, 'permissible', $missingTotalAssets)->status === 'insufficient', 'Missing total assets produces insufficient evidence rather than a pass.');

$researchPass = $engine->screen($policy, 'permissible', $base);
$assert(str_contains(implode(' ', $researchPass->reasons), 'not independent Sharia certification'), 'A research pass snapshot retains the non-certification boundary.');

echo "\n{$passed} passed, {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
