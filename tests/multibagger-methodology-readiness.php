#!/usr/bin/env php
<?php

declare(strict_types=1);

use HalalPulse\Multibagger\MultibaggerMethodology;
use HalalPulse\Multibagger\MultibaggerMethodologyReadinessInspector;
use HalalPulse\Multibagger\MultibaggerMethodologyValidator;
use HalalPulse\Multibagger\MultibaggerScoringEngine;
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

$readJson = static function (string $path): array {
    $json = file_get_contents($path);
    if (!is_string($json)) {
        throw new RuntimeException("Unable to read {$path}.");
    }
    $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($payload)) {
        throw new RuntimeException("{$path} must contain an object.");
    }

    return $payload;
};

$fixture = $readJson(__DIR__ . '/fixtures/multibagger_methodology.json');
$example = $readJson(dirname(__DIR__) . '/config/multibagger-methodology.example.json');
$inspector = new MultibaggerMethodologyReadinessInspector();
$validator = new MultibaggerMethodologyValidator();

$fixtureReview = $inspector->inspect($fixture);
$assert($fixtureReview['ready'] === true, 'Complete synthetic methodology passes the readiness gate.');
$assert(count($fixtureReview['warnings']) === 1, 'Synthetic reduced factor count is warned about without weakening structural tests.');
$assert($inspector->inspect($example, false)['ready'] === false, 'The shipped methodology template remains deliberately unready.');

$missingAnchor = $fixture;
unset($missingAnchor['factors'][0]['grade_anchors']['7']);
$assert($inspector->inspect($missingAnchor)['ready'] === false, 'A missing grade anchor blocks methodology activation.');

$missingRequirements = $fixture;
$missingRequirements['factors'][0]['evidence_requirements'] = ['Only one requirement is not enough.'];
$assert($inspector->inspect($missingRequirements)['ready'] === false, 'Every factor requires at least two explicit evidence requirements.');

$mediaAllowed = $fixture;
$mediaAllowed['review_scope']['media_sources_allowed'] = true;
$assert($inspector->inspect($mediaAllowed)['ready'] === false, 'The methodology cannot allow media sources.');

$missingDcfAssumption = $fixture;
$missingDcfAssumption['valuation_policy']['dcf_required_assumptions'] = array_values(array_filter(
    $missingDcfAssumption['valuation_policy']['dcf_required_assumptions'],
    static fn (string $key): bool => $key !== 'discount_rate',
));
$assert($inspector->inspect($missingDcfAssumption)['ready'] === false, 'A missing required DCF assumption blocks methodology activation.');

$unapproved = $fixture;
$unapproved['approved_for_use'] = false;
$assert($inspector->inspect($unapproved, false)['ready'] === true, 'A complete file can be structurally reviewed before final approval.');
$assert($inspector->inspect($unapproved, true)['ready'] === false, 'The same file cannot be activated before explicit approval.');

$validated = $validator->validate($fixture);
$assert(isset($validated['factors'][0]['grade_anchors']['1']), 'Grade anchors are retained in the canonical methodology snapshot.');
$assert(isset($validated['valuation_policy']['dcf_required_assumptions']), 'DCF review requirements are retained in the canonical methodology snapshot.');
$assert(strlen($validator->hash($fixture)) === 64, 'The expanded methodology receives a canonical SHA-256 identity.');

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
$factorRows = [
    'financial_strength' => [
        'grade' => 2,
        'evidence_note' => 'Synthetic exchange evidence.',
        'evidence_source_url' => 'https://www.nseindia.com/synthetic',
        'source_document_id' => null,
    ],
    'macro_tailwind' => [
        'grade' => 4,
        'evidence_note' => 'Synthetic reviewed government evidence.',
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
    'dcf_assumptions_note' => 'Synthetic DCF assumptions only.',
    'evidence_note' => 'Synthetic valuation evidence.',
    'evidence_source_url' => 'https://www.nseindia.com/synthetic',
];
$risk = [
    'market_cap_crore' => '1000',
    'red_flags' => [],
    'green_flags' => [],
    'evidence_note' => 'Synthetic market-cap evidence.',
    'evidence_source_url' => 'https://www.nseindia.com/synthetic',
];
$engine = new MultibaggerScoringEngine(new DecimalMath());
$assert(
    $engine->score($methodology, ['id' => 1, 'status' => 'passed'], $factorRows, $valuation, $risk)->status === 'scored',
    'Evidence meeting the methodology note standards can be scored.',
);
$shortNoteRows = $factorRows;
$shortNoteRows['financial_strength']['evidence_note'] = 'Too short';
$assert(
    $engine->score($methodology, ['id' => 1, 'status' => 'passed'], $shortNoteRows, $valuation, $risk)->status === 'insufficient',
    'A factor note shorter than the active methodology minimum is rejected.',
);
$shortAssumptions = $valuation;
$shortAssumptions['dcf_assumptions_note'] = 'Brief';
$assert(
    $engine->score($methodology, ['id' => 1, 'status' => 'passed'], $factorRows, $shortAssumptions, $risk)->status === 'insufficient',
    'A DCF assumptions note shorter than the active methodology minimum is rejected.',
);

echo "\n{$passed} passed, {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
