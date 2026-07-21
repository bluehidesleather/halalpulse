#!/usr/bin/env php
<?php

declare(strict_types=1);

use HalalPulse\Multibagger\MultibaggerEvidenceReadiness;
use HalalPulse\Multibagger\MultibaggerMethodology;
use HalalPulse\Multibagger\MultibaggerMethodologyValidator;
use HalalPulse\Sharia\DecimalMath;

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

$json = file_get_contents(__DIR__ . '/fixtures/multibagger_methodology.json');
if (!is_string($json)) {
    throw new RuntimeException('Unable to read methodology fixture.');
}
$payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
if (!is_array($payload)) {
    throw new RuntimeException('Methodology fixture must contain an object.');
}

$validator = new MultibaggerMethodologyValidator();
$validated = $validator->validate($payload);
$methodology = new MultibaggerMethodology(
    id: 1,
    version: $validated['version'],
    name: $validated['name'],
    effectiveDate: $validated['effective_date'],
    verifiedBy: $validated['verified_by'],
    verificationNote: $validated['verification_note'],
    methodologyHash: $validator->hash($validated),
    isActive: true,
    definition: $validated,
);
$service = new MultibaggerEvidenceReadiness(new DecimalMath());
$shariaPass = ['id' => 9, 'status' => 'passed'];
$factors = [
    'financial_strength' => [
        'grade' => 2,
        'evidence_note' => 'Synthetic exchange evidence with calculation detail.',
        'evidence_source_url' => 'https://www.nseindia.com/synthetic',
        'source_document_id' => null,
    ],
    'macro_tailwind' => [
        'grade' => 4,
        'evidence_note' => 'Synthetic reviewed government evidence and transmission rationale.',
        'evidence_source_url' => 'https://www.pib.gov.in/synthetic',
        'source_document_id' => null,
        'government_tailwind_review_id' => 7,
        'government_review_status' => 'current',
        'government_review_impact' => 'moderate_tailwind',
    ],
];
$valuation = [
    'currency' => 'INR',
    'eps' => '4',
    'book_value_per_share' => '100',
    'dcf_value_per_share' => '25',
    'current_price' => '20',
    'dcf_assumptions_note' => 'Synthetic DCF assumptions covering growth and discount rates.',
    'evidence_note' => 'Synthetic valuation evidence from official filings.',
    'evidence_source_url' => 'https://www.nseindia.com/synthetic',
];
$risk = [
    'market_cap_crore' => '1000',
    'red_flags' => [],
    'green_flags' => [],
    'evidence_note' => 'Synthetic market-cap and risk evidence from an official source.',
    'evidence_source_url' => 'https://www.nseindia.com/synthetic',
];

$complete = $service->assess($methodology, $shariaPass, $factors, $valuation, $risk);
$assert($complete['ready'] === true, 'Complete same-period evidence is ready for an immutable score.');
$assert($complete['completed_factor_keys'] === ['financial_strength', 'macro_tailwind'], 'Completed factor keys are deterministic.');
$assert($complete['valuation_ready'] === true && $complete['risk_ready'] === true, 'Valuation and risk evidence pass readiness.');

$withoutMethodology = $service->assess(null, $shariaPass, $factors, $valuation, $risk);
$assert($withoutMethodology['ready'] === false, 'Scoring remains blocked without an active methodology.');

$withoutSharia = $service->assess($methodology, null, $factors, $valuation, $risk);
$assert($withoutSharia['ready'] === false && $withoutSharia['sharia_ready'] === false, 'A same-period Sharia pass is mandatory.');

$missingFactor = $factors;
unset($missingFactor['financial_strength']);
$missing = $service->assess($methodology, $shariaPass, $missingFactor, $valuation, $risk);
$assert($missing['ready'] === false, 'A missing required factor blocks scoring.');
$assert($missing['missing_factor_keys'] === ['financial_strength'], 'The exact missing factor is reported.');

$shortFactor = $factors;
$shortFactor['financial_strength']['evidence_note'] = 'Too short';
$assert($service->assess($methodology, $shariaPass, $shortFactor, $valuation, $risk)['ready'] === false, 'A factor note below the methodology minimum blocks scoring.');

$staleMacro = $factors;
$staleMacro['macro_tailwind']['government_review_status'] = 'superseded';
$assert($service->assess($methodology, $shariaPass, $staleMacro, $valuation, $risk)['ready'] === false, 'A stale government review blocks the macro factor.');

$badValuation = $valuation;
$badValuation['eps'] = '0';
$assert($service->assess($methodology, $shariaPass, $factors, $badValuation, $risk)['valuation_ready'] === false, 'Non-positive valuation evidence blocks readiness.');

$shortAssumptions = $valuation;
$shortAssumptions['dcf_assumptions_note'] = 'Brief';
$assert($service->assess($methodology, $shariaPass, $factors, $shortAssumptions, $risk)['ready'] === false, 'Insufficient DCF assumptions block readiness.');

$unknownRisk = $risk;
$unknownRisk['red_flags'] = ['not_in_methodology'];
$assert($service->assess($methodology, $shariaPass, $factors, $valuation, $unknownRisk)['risk_ready'] === false, 'Unknown risk flags are rejected rather than silently ignored.');

$valuationDisagrees = $valuation;
$valuationDisagrees['current_price'] = '30';
$disagreement = $service->assess($methodology, $shariaPass, $factors, $valuationDisagrees, $risk);
$assert($disagreement['ready'] === true, 'Valuation disagreement does not block a complete score.');
$assert($disagreement['warnings'] !== [], 'Valuation disagreement is surfaced as a research warning.');

$inactive = new MultibaggerMethodology(
    id: $methodology->id,
    version: $methodology->version,
    name: $methodology->name,
    effectiveDate: $methodology->effectiveDate,
    verifiedBy: $methodology->verifiedBy,
    verificationNote: $methodology->verificationNote,
    methodologyHash: $methodology->methodologyHash,
    isActive: false,
    definition: $methodology->definition,
);
$assert($service->assess($inactive, $shariaPass, $factors, $valuation, $risk)['ready'] === false, 'An inactive methodology cannot authorize scoring.');

echo "\n{$passed} passed, {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
