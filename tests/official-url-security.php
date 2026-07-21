#!/usr/bin/env php
<?php

declare(strict_types=1);

use HalalPulse\Government\GovernmentOfficialUrl;
use HalalPulse\Multibagger\ResearchEvidenceUrl;
use HalalPulse\Nse\NseIntegratedUrl;
use HalalPulse\Support\OfficialHttpsUrl;
use HalalPulse\Support\OfficialUrl;

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

$base = 'https://www.bseindia.com/xml-data/corpfiling/AttachLive/';
$hosts = ['www.bseindia.com'];
$resolved = OfficialUrl::attachment('Synthetic Result.pdf', $base, $hosts);
$assert($resolved === $base . 'Synthetic%20Result.pdf', 'A relative official attachment with spaces resolves safely.');
$assert(OfficialUrl::attachment('//www.bseindia.com/official.pdf', $base, $hosts) === 'https://www.bseindia.com/official.pdf', 'A protocol-relative official attachment is normalized to HTTPS.');
$assert(OfficialUrl::attachment('https://www.bseindia.com:443/official.pdf', $base, $hosts) !== null, 'An explicit standard HTTPS port remains valid.');
$assert(OfficialUrl::attachment('https://www.bseindia.com:8443/official.pdf', $base, $hosts) === null, 'A nonstandard port is rejected before an attachment is stored.');
$credentialAttachment = 'https://' . 'synthetic-user' . ':' . 'synthetic-pass' . '@www.bseindia.com/official.pdf';
$assert(OfficialUrl::attachment($credentialAttachment, $base, $hosts) === null, 'Credential-bearing attachment URLs are rejected.');
$assert(OfficialUrl::attachment('https://www.bseindia.com/official.pdf#section', $base, $hosts) === null, 'Attachment fragments are rejected.');
$assert(OfficialUrl::attachment('../private.pdf', $base, $hosts) === null, 'Relative parent traversal is rejected.');
$assert(OfficialUrl::attachment('%252e%252e/private.pdf', $base, $hosts) === null, 'Double-encoded parent traversal is rejected.');
$assert(OfficialUrl::attachment('folder%5c..%5cprivate.pdf', $base, $hosts) === null, 'Encoded backslash traversal is rejected.');
$assert(OfficialUrl::attachment("official\nInjected.pdf", $base, $hosts) === null, 'Control bytes in attachment values are rejected.');

$assert(GovernmentOfficialUrl::isAllowed('https://www.pib.gov.in/PressReleasePage.aspx?PRID=SYNTHETIC', 'PIB'), 'A source-specific government URL remains valid.');
$assert(!GovernmentOfficialUrl::isAllowed('https://www.pib.gov.in:8443/PressReleasePage.aspx?PRID=SYNTHETIC', 'PIB'), 'Government evidence on a nonstandard port is rejected.');
$assert(!GovernmentOfficialUrl::isAllowed('https://www.pib.gov.in/PressReleasePage.aspx?PRID=SYNTHETIC#section', 'PIB'), 'Government evidence fragments are rejected.');
$assert(!GovernmentOfficialUrl::isAllowed('https://www.pib.gov.in/%252e%252e/private', 'PIB'), 'Encoded traversal in government evidence is rejected.');
$assert(!GovernmentOfficialUrl::isAllowed('https://www.pib.gov.in/official', 'RBI'), 'A government host assigned to the wrong source remains rejected.');

$assert(ResearchEvidenceUrl::isAllowed('https://www.sebi.gov.in/media/press-releases/example.html?source=official'), 'Official financial evidence with a query string remains valid.');
$assert(!ResearchEvidenceUrl::isAllowed('https://www.sebi.gov.in:8443/media/press-releases/example.html'), 'Research evidence on a nonstandard port is rejected.');
$assert(!ResearchEvidenceUrl::isAllowed('https://www.sebi.gov.in/media/press-releases/example.html#claim'), 'Research evidence fragments are rejected.');
$assert(!ResearchEvidenceUrl::isAllowed('https://www.sebi.gov.in/%2e%2e/private'), 'Research evidence with encoded traversal is rejected.');
$assert(!ResearchEvidenceUrl::isAllowed('https://www.nseindia.com/official', true), 'Government-only evidence rejects a financial-exchange host.');

$xbrl = 'https://nsearchives.nseindia.com/corporate/xbrl/INTEGRATED_FILING_INDAS_1697533_20072026080318_WEB.xml';
$assert(NseIntegratedUrl::isAllowedXbrl($xbrl), 'The exact NSE Integrated archive contract remains valid.');
$assert(NseIntegratedUrl::isAllowedXbrl(str_replace('.com/', '.com:443/', $xbrl)), 'An explicit standard HTTPS port remains valid for the NSE archive.');
$assert(!NseIntegratedUrl::isAllowedXbrl(str_replace('.com/', '.com:8443/', $xbrl)), 'A nonstandard NSE archive port is rejected.');
$assert(!NseIntegratedUrl::isAllowedXbrl($xbrl . '#fragment'), 'NSE archive fragments are rejected.');
$assert(!NseIntegratedUrl::isAllowedXbrl($xbrl . '?download=1'), 'NSE archive query changes remain rejected.');

$assert(OfficialHttpsUrl::hasUnsafePath('/safe/path/file.pdf') === false, 'A normal official path is not marked unsafe.');
$assert(OfficialHttpsUrl::hasUnsafePath('/safe/%252e%252e/file.pdf'), 'Repeatedly encoded traversal is detected by the shared policy.');
$assert(!OfficialHttpsUrl::isAllowed('https://www.bseindia.com/official.pdf', []), 'An empty official host policy fails closed.');

echo "\n{$passed} passed, {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
