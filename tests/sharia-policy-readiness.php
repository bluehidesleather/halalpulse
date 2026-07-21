#!/usr/bin/env php
<?php

declare(strict_types=1);

use HalalPulse\Sharia\ShariaPolicyReadinessInspector;
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

$readJson = static function (string $path): array {
    $json = file_get_contents($path);
    if (!is_string($json)) {
        throw new RuntimeException("Unable to read {$path}");
    }

    $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($payload)) {
        throw new RuntimeException("JSON object expected in {$path}");
    }

    return $payload;
};

$inspector = new ShariaPolicyReadinessInspector();
$validator = new ShariaPolicyValidator();
$synthetic = $readJson(__DIR__ . '/fixtures/sharia_policy.json');

$inspection = $inspector->inspect($synthetic);
$assert($inspection['ready'] === true, 'A complete synthetic policy passes the non-mutating readiness inspection.');
$validated = $validator->validate($synthetic);
$assert($validated['ratios'][0]['source_clause'] === 'Synthetic clause 1.1', 'Clause provenance survives canonical policy validation.');
$assert(str_contains($validated['ratios'][0]['numerator_definition'], 'Synthetic test debt'), 'Numerator definition survives canonical policy validation.');

$draft = $synthetic;
$draft['approved_for_use'] = false;
$assert($inspector->inspect($draft, false)['ready'] === true, 'A complete draft can be inspected without pretending that it is approved.');
$assert($inspector->inspect($draft, true)['ready'] === false, 'Activation readiness remains blocked until explicit approval.');

$missingClause = $synthetic;
unset($missingClause['ratios'][0]['source_clause']);
$missingClauseRejected = false;
try {
    $validator->validate($missingClause);
} catch (InvalidArgumentException) {
    $missingClauseRejected = true;
}
$assert($missingClauseRejected, 'Activation validation rejects a ratio without an exact source clause.');

$officialAaoifi = $synthetic;
$officialAaoifi['authority_name'] = 'AAOIFI';
$officialAaoifi['authority_standard'] = 'Sharia Standard No. 21';
$officialAaoifi['authority_reference_url'] = 'https://aaoifi.com/ss-21-financial-paper-shares-and-bonds/?lang=en';
$assert($inspector->inspect($officialAaoifi)['ready'] === true, 'An AAOIFI policy may cite the official Standard No. 21 page.');

$draftAaoifi = $officialAaoifi;
$draftAaoifi['authority_reference_url'] = 'https://aaoifi.com/announcement/draft-english-translation-of-aaoifi-shariah-standards-1-61/?lang=en';
$assert($inspector->inspect($draftAaoifi)['ready'] === false, 'An AAOIFI draft or announcement page cannot serve as the governing policy source.');

$thirdPartyAaoifi = $officialAaoifi;
$thirdPartyAaoifi['authority_reference_url'] = 'https://example.com/aaoifi-standard-21';
$assert($inspector->inspect($thirdPartyAaoifi)['ready'] === false, 'An AAOIFI policy cannot cite a third-party copy as the governing source.');

$template = $readJson(dirname(__DIR__) . '/config/sharia-policy.example.json');
$templateInspection = $inspector->inspect($template, false);
$assert($templateInspection['ready'] === false, 'The shipped policy template remains deliberately unready and cannot be activated accidentally.');
$assert(count($templateInspection['errors']) >= 3, 'The readiness inspection reports multiple unresolved template fields at once.');

echo "\n{$passed} passed, {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
