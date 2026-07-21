#!/usr/bin/env php
<?php

declare(strict_types=1);

use HalalPulse\Sharia\DecimalMath;
use HalalPulse\Sharia\ShariaActivityReviewValidator;
use HalalPulse\Sharia\ShariaEvidenceReadiness;
use HalalPulse\Sharia\ShariaPolicy;
use HalalPulse\Sharia\ShariaPolicyValidator;

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
$throws = static function (callable $callback): bool {
    try {
        $callback();
    } catch (InvalidArgumentException) {
        return true;
    }

    return false;
};

$json = file_get_contents(__DIR__ . '/fixtures/sharia_policy.json');
if (!is_string($json)) {
    throw new RuntimeException('Unable to read synthetic Sharia policy fixture.');
}
$payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
if (!is_array($payload)) {
    throw new RuntimeException('Synthetic Sharia policy fixture must contain an object.');
}
$validated = (new ShariaPolicyValidator())->validate($payload);
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
    policyHash: hash('sha256', 'synthetic-evidence-readiness'),
    isActive: true,
    ratios: $validated['ratios'],
);

$activityValidator = new ShariaActivityReviewValidator();
$assert(
    $throws(static fn () => $activityValidator->validate('pending', '/', '', '/')),
    'Placeholder slashes cannot be saved as a business-activity review.',
);
$assert(
    $throws(static fn () => $activityValidator->validate(
        'permissible',
        'The company manufactures synthetic test products for industrial customers.',
        '',
        'The reviewed business description supports a permissible synthetic classification.',
    )),
    'A decisive activity classification requires a primary evidence URL.',
);
$pendingReview = $activityValidator->validate(
    'pending',
    'The company activity is still being verified against primary corporate disclosures.',
    '',
    'The current evidence is incomplete, so no decisive classification has been recorded.',
);
$assert($pendingReview['source_url'] === null, 'A meaningful pending review may be retained without pretending a source is complete.');
$permissibleReview = $activityValidator->validate(
    'permissible',
    'The company manufactures synthetic test products for industrial customers.',
    'https://example.test/company/activities',
    'The primary corporate activity page describes only the synthetic manufacturing business reviewed here.',
);
$assert($permissibleReview['status'] === 'permissible', 'A complete primary-evidence activity review passes validation.');
$assert(
    $throws(static fn () => $activityValidator->validate(
        'prohibited',
        'The company operates a synthetic prohibited business for automated tests.',
        'https://127.0.0.1/private',
        'The synthetic primary evidence directly supports the prohibited classification used in this test.',
    )),
    'Local or private evidence URLs are rejected.',
);

$readiness = new ShariaEvidenceReadiness(new DecimalMath());
$noPolicy = $readiness->assess(null, null, []);
$assert($noPolicy['ready'] === false, 'Screening remains blocked without an active policy.');
$assert(count($noPolicy['blockers']) === 2, 'Policy and activity blockers are reported together.');

$noActivity = $readiness->assess($policy, null, []);
$assert($noActivity['ready'] === false, 'Screening remains blocked without a business-activity review.');

$prohibited = $readiness->assess($policy, ['activity_status' => 'prohibited'], []);
$assert($prohibited['ready'] === true, 'A prohibited activity review is ready to record a failure without financial ratios.');
$assert($prohibited['required_input_keys'] === [], 'Prohibited activity does not demand irrelevant financial evidence.');

$missing = $readiness->assess(
    $policy,
    ['activity_status' => 'permissible'],
    [],
    [[
        'metric_key' => 'test_total_revenue',
        'review_status' => 'pending',
    ]],
);
$assert($missing['ready'] === false, 'A pending candidate does not count as accepted policy evidence.');
$assert(in_array('test_total_revenue', $missing['missing_input_keys'], true), 'The missing-input list identifies the candidate-backed input.');
$assert(count($missing['warnings']) === 1, 'A pending candidate is surfaced as a review opportunity.');

$completeInputs = [
    'test_debt' => ['value' => '20', 'currency' => 'INR', 'scale_label' => 'crore', 'source_document_id' => 1, 'evidence_note' => 'Synthetic debt evidence.'],
    'test_reference_value' => ['value' => '100', 'currency' => 'INR', 'scale_label' => 'crore', 'source_document_id' => 1, 'evidence_note' => 'Synthetic reference evidence.'],
    'test_flagged_income' => ['value' => '5', 'currency' => 'INR', 'scale_label' => 'crore', 'source_document_id' => 1, 'evidence_note' => 'Synthetic flagged income evidence.'],
    'test_total_revenue' => ['value' => '100', 'currency' => 'INR', 'scale_label' => 'crore', 'source_document_id' => 1, 'evidence_note' => 'Synthetic revenue evidence.'],
];
$complete = $readiness->assess($policy, ['activity_status' => 'permissible'], $completeInputs);
$assert($complete['ready'] === true, 'A permissible activity and complete internally consistent evidence set are ready for screening.');
$assert($complete['missing_input_keys'] === [], 'A complete evidence set has no missing required inputs.');

$currencyMismatch = $completeInputs;
$currencyMismatch['test_reference_value']['currency'] = 'USD';
$mismatch = $readiness->assess($policy, ['activity_status' => 'permissible'], $currencyMismatch);
$assert($mismatch['ready'] === false, 'Currency mismatch blocks screening before an immutable result is recorded.');

$zeroDenominator = $completeInputs;
$zeroDenominator['test_total_revenue']['value'] = '0';
$zero = $readiness->assess($policy, ['activity_status' => 'permissible'], $zeroDenominator);
$assert($zero['ready'] === false, 'A zero denominator blocks screening before calculation.');

echo "\n{$passed} passed, {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
