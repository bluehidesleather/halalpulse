#!/usr/bin/env php
<?php

declare(strict_types=1);

use HalalPulse\Ingestion\Filing;
use HalalPulse\Ingestion\BseAnnouncementMapper;
use HalalPulse\Ingestion\NseAnnouncementMapper;
use HalalPulse\Ingestion\QuarterlyResultClassifier;
use HalalPulse\Auth\PasswordPolicy;
use HalalPulse\Auth\PasswordHasher;
use HalalPulse\Auth\UserRepository;
use HalalPulse\Support\OfficialUrl;
use HalalPulse\Web\Page;
use HalalPulse\Documents\DocumentQueueItem;
use HalalPulse\Documents\MetricCandidateExtractor;
use HalalPulse\Documents\OfficialDocumentDownloader;
use HalalPulse\Documents\UnsupportedDocumentException;
use HalalPulse\Http\HttpClient;
use HalalPulse\Http\HttpResponse;
use HalalPulse\Sharia\DecimalMath;
use HalalPulse\Sharia\ShariaPolicy;
use HalalPulse\Sharia\ShariaPolicyValidator;
use HalalPulse\Sharia\ShariaScreeningEngine;
use HalalPulse\Multibagger\MultibaggerMethodology;
use HalalPulse\Multibagger\MultibaggerMethodologyValidator;
use HalalPulse\Multibagger\MultibaggerScoringEngine;
use HalalPulse\Multibagger\ResearchEvidenceUrl;
use HalalPulse\Government\GovernmentAnnouncement;
use HalalPulse\Government\GovernmentOfficialUrl;
use HalalPulse\Government\GovernmentSectorClassifier;
use HalalPulse\Government\OfficialListingMapper;
use HalalPulse\Government\RssAnnouncementMapper;
use HalalPulse\Alerts\AlertConfiguration;
use HalalPulse\Alerts\AlertDispatcher;
use HalalPulse\Alerts\AlertMessageBuilder;
use HalalPulse\Alerts\AlertRecipientCrypto;

require dirname(__DIR__) . '/app/bootstrap.php';

$classifier = new QuarterlyResultClassifier();
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

$makeFiling = static function (string $subject, string $category = 'Corporate Announcement'): Filing {
    return new Filing(
        exchange: 'NSE',
        sourceId: hash('sha256', $category . $subject),
        symbol: 'DEMO',
        companyName: 'Demonstration Limited',
        category: $category,
        subject: $subject,
        announcedAt: new DateTimeImmutable('2026-07-19 12:00:00'),
        attachmentUrl: 'https://example.com/result.pdf',
        rawPayload: ['subject' => $subject],
    );
};

$strong = $classifier->classify($makeFiling(
    'Unaudited standalone and consolidated financial results for the quarter ended June 30, 2026'
));
$assert($strong['is_candidate'] === true, 'Strong quarterly-result announcement is detected.');
$assert($strong['confidence'] === 100, 'Strong quarterly-result announcement receives 100 confidence.');

$generic = $classifier->classify($makeFiling('Financial Results', 'Financial Results'));
$assert($generic['is_candidate'] === true, 'Generic financial-results category remains a review candidate.');
$assert($generic['confidence'] === 70, 'Generic financial-results candidate receives bounded confidence.');

$window = $classifier->classify($makeFiling(
    'Closure of trading window for declaration of unaudited financial results for the quarter'
));
$assert($window['is_candidate'] === false, 'Trading-window notice is excluded.');

$meeting = $classifier->classify($makeFiling(
    'Board meeting intimation to consider financial results for the quarter ended June 30, 2026'
));
$assert($meeting['is_candidate'] === false, 'Board-meeting intimation is excluded.');

$dividend = $classifier->classify($makeFiling('Declaration of interim dividend'));
$assert($dividend['is_candidate'] === false, 'Unrelated corporate action is not classified as a result.');

$filing = $makeFiling('Financial Results');
$assert(strlen($filing->payloadHash()) === 64, 'Raw payload produces a SHA-256 hash.');

$fixture = static function (string $name): array {
    $path = __DIR__ . '/fixtures/' . $name;
    $json = file_get_contents($path);

    if ($json === false) {
        throw new RuntimeException("Unable to read fixture: {$name}");
    }

    $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($payload)) {
        throw new RuntimeException("Fixture must decode to an array: {$name}");
    }

    return $payload;
};

$nse = (new NseAnnouncementMapper())->map($fixture('nse_announcements.json'));
$assert($nse->sourceRows === 3, 'NSE mapper counts every source row.');
$assert(count($nse->filings) === 2, 'NSE mapper skips one incomplete synthetic row.');
$assert($nse->skippedRows() === 1, 'NSE mapping result exposes its skipped-row count.');
$assert($nse->filings[0]->sourceId === 'nse:SYNTHETIC-NSE-001', 'NSE native sequence ID is normalized.');
$assert($nse->filings[0]->announcedAt->format('Y-m-d H:i:s') === '2026-07-19 14:30:00', 'NSE timestamp is parsed in exchange time.');
$assert($nse->filings[1]->attachmentUrl === null, 'NSE mapper rejects a non-official attachment host.');
$nseAgain = (new NseAnnouncementMapper())->map($fixture('nse_announcements.json'));
$assert($nse->filings[1]->sourceId === $nseAgain->filings[1]->sourceId, 'NSE fallback filing ID is deterministic.');

$bse = (new BseAnnouncementMapper())->map($fixture('bse_announcements.json'));
$assert($bse->sourceRows === 3, 'BSE mapper reads the Table response envelope.');
$assert(count($bse->filings) === 2, 'BSE mapper skips one incomplete synthetic row.');
$assert($bse->skippedRows() === 1, 'BSE mapping result exposes its skipped-row count.');
$assert($bse->filings[0]->sourceId === 'bse:SYNTHETIC-BSE-001', 'BSE native news ID is normalized.');
$assert($bse->filings[0]->attachmentUrl === 'https://www.bseindia.com/xml-data/corpfiling/AttachLive/SYNTHETIC_EXAMPLE_RESULT.pdf', 'BSE attachment filename resolves only to the official host.');
$bseAgain = (new BseAnnouncementMapper())->map($fixture('bse_announcements.json'));
$assert($bse->filings[1]->sourceId === $bseAgain->filings[1]->sourceId, 'BSE fallback filing ID is deterministic.');

$blocked = OfficialUrl::attachment(
    'https://example.com/not-official.pdf',
    'https://www.bseindia.com/xml-data/corpfiling/AttachLive/',
    ['www.bseindia.com'],
);
$assert($blocked === null, 'Official URL guard rejects a third-party host.');

$assert(GovernmentOfficialUrl::isAllowed('https://www.pib.gov.in/PressReleasePage.aspx?PRID=SYNTHETIC', 'PIB'), 'Government URL guard accepts a source-specific official PIB URL.');
$assert(!GovernmentOfficialUrl::isAllowed('https://www.pib.gov.in/synthetic', 'RBI'), 'Government URL guard rejects an official host assigned to the wrong source.');

if (extension_loaded('dom')) {
    $pibFixture = file_get_contents(__DIR__ . '/fixtures/pib_feed.xml');
    if (!is_string($pibFixture)) {
        throw new RuntimeException('Unable to read synthetic PIB fixture.');
    }
    $pibResult = (new RssAnnouncementMapper('PIB', ['item']))->map($pibFixture);
    $assert($pibResult->sourceRows === 2, 'Government RSS mapper counts every synthetic source row.');
    $assert(count($pibResult->announcements) === 1, 'Government RSS mapper skips a third-party item link.');
    $assert($pibResult->announcements[0]->source === 'PIB', 'Government RSS mapper retains the official source identity.');
    $assert($pibResult->announcements[0]->publishedAt->format('Y-m-d H:i:s') === '2026-07-19 10:00:00', 'Government RSS mapper retains the publication timestamp.');

    $sebiFixture = file_get_contents(__DIR__ . '/fixtures/sebi_listing.html');
    if (!is_string($sebiFixture)) {
        throw new RuntimeException('Unable to read synthetic SEBI fixture.');
    }
    $sebiResult = (new OfficialListingMapper(
        source: 'SEBI',
        baseUrl: 'https://www.sebi.gov.in/sebiweb/home/HomeAction.do',
        category: 'Press release',
        requiredMarkers: ['SEBI', 'Press Releases'],
        linkPathContains: ['/media/press-releases/'],
    ))->map($sebiFixture);
    $assert($sebiResult->sourceRows === 2, 'Official listing mapper counts every matching synthetic link.');
    $assert(count($sebiResult->announcements) === 1, 'Official listing mapper rejects a matching path on a third-party host.');
    $assert($sebiResult->announcements[0]->publishedAt->format('Y-m-d') === '2026-07-19', 'Official listing mapper extracts its nearby publication date.');
} else {
    $assert(false, 'Government parsers require the PHP DOM extension.');
}

$syntheticGovernmentAnnouncement = new GovernmentAnnouncement(
    source: 'PIB',
    sourceId: 'pib:synthetic-classifier',
    category: 'Cabinet',
    title: 'Government approves renewable energy manufacturing incentive scheme',
    summary: 'Synthetic solar manufacturing support fixture.',
    publishedAt: new DateTimeImmutable('2026-07-19 10:00:00'),
    officialUrl: 'https://www.pib.gov.in/PressReleasePage.aspx?PRID=SYNTHETIC',
    rawPayload: ['synthetic' => true],
);
$governmentClassification = (new GovernmentSectorClassifier())->classify($syntheticGovernmentAnnouncement);
$assert($governmentClassification->sector === 'Renewable energy', 'Government classifier suggests a bounded sector from explicit phrases.');
$assert($governmentClassification->suggestedImpact === 'tailwind', 'Government classifier suggests direction only when sector and policy-action phrases both match.');
$assert($governmentClassification->confidence <= 85, 'Government classifier confidence is capped below automatic approval.');

$syntheticAlertConfiguration = new AlertConfiguration(
    enabled: true,
    channel: 'telegram',
    batchSize: 1,
    recipientLimit: 25,
    appBaseUrl: 'https://halalpulse.example',
    botToken: '123456789:' . str_repeat('A', 35),
    timeoutSeconds: 20,
    maxResponseBytes: 1_048_576,
);
$alertConfigurationAccepted = true;
try {
    $syntheticAlertConfiguration->assertReady();
} catch (InvalidArgumentException) {
    $alertConfigurationAccepted = false;
}
$assert($alertConfigurationAccepted, 'Complete synthetic Telegram configuration passes local validation.');
if (extension_loaded('openssl')) {
    $recipientCrypto = new AlertRecipientCrypto(str_repeat('k', 32));
    $protectedRecipient = $recipientCrypto->encryptTelegramChatId('123456789');
    $assert(strlen($protectedRecipient['recipient_hash']) === 64 && !str_contains($protectedRecipient['ciphertext'], '123456789'), 'Telegram chat ID is encrypted and represented by a keyed identity hash.');
    $assert($recipientCrypto->decryptTelegramChatId($protectedRecipient['ciphertext'], $protectedRecipient['nonce'], $protectedRecipient['tag'], $protectedRecipient['recipient_hash']) === '123456789', 'Encrypted Telegram chat ID decrypts only with its application key and integrity metadata.');
} else {
    $assert(false, 'Telegram recipient protection requires the PHP OpenSSL extension.');
    $assert(false, 'Telegram recipient decryption requires the PHP OpenSSL extension.');
}
$alertBuilder = new AlertMessageBuilder($syntheticAlertConfiguration->appBaseUrl);
$syntheticAlertBody = $alertBuilder->build([
    'company_id' => 7,
    'final_score' => 3,
    'symbol' => 'SYNTH',
    'exchange' => 'NSE',
    'company_name' => 'Synthetic Company Limited',
    'period_end' => '2026-06-30',
    'undervalued_by_both' => 1,
]);
$assert(mb_strlen($syntheticAlertBody) <= 4096, 'Alert message remains within the Telegram body limit.');
$assert(str_contains($syntheticAlertBody, 'not financial or religious advice'), 'Alert message contains the required risk disclaimer.');
$outOfGateRejected = false;
try {
    $alertBuilder->build(['company_id' => 7, 'final_score' => 5]);
} catch (InvalidArgumentException) {
    $outOfGateRejected = true;
}
$assert($outOfGateRejected, 'Alert builder refuses a score above the locked alert ceiling.');
$assert(!str_contains(AlertDispatcher::safeError('Chat 123456789 rejected token 123456789:' . str_repeat('A', 35) . '.', ['123456789']), '123456789'), 'Provider error sanitization removes Telegram chat IDs and bot tokens before persistence.');

$passwordPolicy = new PasswordPolicy();
$assert($passwordPolicy->isValid('a long unique passphrase') === true, 'Password policy accepts a 12+ character passphrase.');
$assert($passwordPolicy->isValid('too-short') === false, 'Password policy rejects a short password.');
$assert($passwordPolicy->isValid(str_repeat('x', 129)) === false, 'Password policy enforces its maximum length.');
$assert(UserRepository::normalizeEmail('  ADMIN@Example.COM ') === 'admin@example.com', 'Login identity normalization is deterministic.');

$passwordHasher = new PasswordHasher();
$passwordHash = $passwordHasher->hash('a long unique passphrase');
$assert($passwordHasher->verify('a long unique passphrase', $passwordHash) === true, 'Password hasher verifies the correct password.');
$assert($passwordHasher->verify('a different passphrase', $passwordHash) === false, 'Password hasher rejects an incorrect password.');
$assert(Page::escape('<script>alert("x")</script>') === '&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;', 'HTML output escaping blocks executable markup.');

$quarterlyText = file_get_contents(__DIR__ . '/fixtures/quarterly_result.txt');
if (!is_string($quarterlyText)) {
    throw new RuntimeException('Unable to read quarterly-result text fixture.');
}

$metricCandidates = (new MetricCandidateExtractor())->extract($quarterlyText);
$assert(count($metricCandidates) === 6, 'Metric extractor finds six supported synthetic financial lines.');
$assert($metricCandidates[0]->metricKey === 'revenue', 'Metric extractor labels revenue conservatively.');
$assert($metricCandidates[0]->currentValue === '1250.50', 'Metric extractor normalizes Indian comma-formatted values.');
$assert($metricCandidates[0]->comparisonValue === '1100.25', 'Metric extractor retains the adjacent comparison value.');
$assert($metricCandidates[0]->currency === 'INR', 'Metric extractor detects INR from document evidence.');
$assert($metricCandidates[0]->scaleLabel === 'crore', 'Metric extractor detects crore units from document evidence.');
$assert($metricCandidates[0]->statementScope === 'standalone', 'Metric extractor detects a single statement scope.');
$assert((new MetricCandidateExtractor())->extract('No financial statement values are present.') === [], 'Metric extractor does not invent missing values.');

$pdfBody = "%PDF-1.4\n" . str_repeat('synthetic-pdf-byte-', 10);
$fakeHttp = new class ($pdfBody) implements HttpClient {
    public function __construct(private readonly string $body)
    {
    }

    public function get(string $url, array $headers = []): HttpResponse
    {
        return new HttpResponse(200, ['content-type' => ['application/pdf']], $this->body);
    }
};
$temporaryRoot = sys_get_temp_dir() . '/halalpulse-document-test-' . bin2hex(random_bytes(5));
$downloadItem = new DocumentQueueItem(
    documentId: 1,
    filingId: 99,
    exchange: 'NSE',
    sourceUrl: 'https://nsearchives.nseindia.com/corporate/synthetic.pdf',
    announcedAt: new DateTimeImmutable('2026-07-19 12:00:00'),
);
$downloaded = (new OfficialDocumentDownloader($fakeHttp, $temporaryRoot))->download($downloadItem);
$assert(is_file($downloaded->absolutePath), 'Document downloader stores a valid PDF outside the web root.');
$assert($downloaded->sha256 === hash('sha256', $pdfBody), 'Document downloader records the exact PDF SHA-256.');
$assert(str_starts_with($downloaded->relativePath, 'nse/2026/07/filing-99-'), 'Document downloader creates a deterministic private path.');

unlink($downloaded->absolutePath);
rmdir(dirname($downloaded->absolutePath));
rmdir(dirname(dirname($downloaded->absolutePath)));
rmdir(dirname(dirname(dirname($downloaded->absolutePath))));
rmdir($temporaryRoot);

$htmlHttp = new class implements HttpClient {
    public function get(string $url, array $headers = []): HttpResponse
    {
        return new HttpResponse(200, ['content-type' => ['text/html']], str_repeat('<html>blocked</html>', 10));
    }
};
$unsupportedRejected = false;

try {
    (new OfficialDocumentDownloader($htmlHttp, sys_get_temp_dir()))->download($downloadItem);
} catch (UnsupportedDocumentException) {
    $unsupportedRejected = true;
}

$assert($unsupportedRejected, 'Document downloader rejects a non-PDF response.');

$syntheticPolicyPayload = $fixture('sharia_policy.json');
$policyValidator = new ShariaPolicyValidator();
$validatedPolicy = $policyValidator->validate($syntheticPolicyPayload);
$assert($validatedPolicy['version'] === 'synthetic-test-v1', 'Policy validator accepts a complete approved synthetic policy.');
$assert(strlen($policyValidator->hash($syntheticPolicyPayload)) === 64, 'Policy validator creates a deterministic SHA-256 identity.');

$unapprovedPolicy = $syntheticPolicyPayload;
$unapprovedPolicy['approved_for_use'] = false;
$unapprovedRejected = false;
try {
    $policyValidator->validate($unapprovedPolicy);
} catch (InvalidArgumentException) {
    $unapprovedRejected = true;
}
$assert($unapprovedRejected, 'Policy validator refuses a policy that has not been explicitly approved.');

$missingThresholdPolicy = $syntheticPolicyPayload;
$missingThresholdPolicy['ratios'][0]['max_percent'] = null;
$missingThresholdRejected = false;
try {
    $policyValidator->validate($missingThresholdPolicy);
} catch (InvalidArgumentException) {
    $missingThresholdRejected = true;
}
$assert($missingThresholdRejected, 'Policy validator refuses a missing ratio threshold instead of guessing.');

$assert(extension_loaded('bcmath'), 'Exact Sharia screening requires the bcmath extension.');
if (extension_loaded('bcmath')) {
    $decimalMath = new DecimalMath();
    $assert($decimalMath->normalize('20') === '20', 'Decimal normalization preserves significant integer zeroes.');
    $testPolicy = new ShariaPolicy(
        id: 1,
        version: $validatedPolicy['version'],
        name: $validatedPolicy['name'],
        authorityName: $validatedPolicy['authority_name'],
        authorityStandard: $validatedPolicy['authority_standard'],
        authorityReferenceUrl: $validatedPolicy['authority_reference_url'],
        effectiveDate: $validatedPolicy['effective_date'],
        verifiedBy: $validatedPolicy['verified_by'],
        verificationNote: $validatedPolicy['verification_note'],
        policyHash: $policyValidator->hash($syntheticPolicyPayload),
        isActive: true,
        ratios: $validatedPolicy['ratios'],
    );
    $screeningEngine = new ShariaScreeningEngine($decimalMath);
    $makeInput = static fn (string $value, string $scale = 'crore', string $currency = 'INR'): array => [
        'value' => $value,
        'currency' => $currency,
        'scale_label' => $scale,
        'source_document_id' => 1,
        'evidence_note' => 'Synthetic test evidence.',
    ];

    $passingInputs = [
        'test_debt' => $makeInput('20'),
        'test_reference_value' => $makeInput('100'),
        'test_flagged_income' => $makeInput('5', 'lakh'),
        'test_total_revenue' => $makeInput('100', 'lakh'),
    ];
    $passingScreen = $screeningEngine->screen($testPolicy, 'permissible', $passingInputs);
    $assert($passingScreen->status === 'passed', 'Complete synthetic evidence within all maxima passes.');
    $assert($passingScreen->complianceRank === 5, 'Custom rank 5 is assigned when worst threshold utilization is 50 percent.');

    $boundaryInputs = $passingInputs;
    $boundaryInputs['test_debt'] = $makeInput('40');
    $boundaryScreen = $screeningEngine->screen($testPolicy, 'permissible', $boundaryInputs);
    $assert($boundaryScreen->status === 'passed', 'An exact decimal value on the synthetic maximum passes.');
    $assert($boundaryScreen->complianceRank === 1, 'A passing value at the maximum receives custom rank 1.');

    $failingInputs = $passingInputs;
    $failingInputs['test_debt'] = $makeInput('40.000001');
    $failingScreen = $screeningEngine->screen($testPolicy, 'permissible', $failingInputs);
    $assert($failingScreen->status === 'failed', 'A value just above the exact synthetic maximum fails.');
    $assert($failingScreen->complianceRank === null, 'Failed screenings do not receive a compliance rank.');

    $incompleteInputs = $passingInputs;
    unset($incompleteInputs['test_total_revenue']);
    $assert($screeningEngine->screen($testPolicy, 'permissible', $incompleteInputs)->status === 'insufficient', 'A missing required denominator produces insufficient, not pass.');

    $mismatchedInputs = $passingInputs;
    $mismatchedInputs['test_total_revenue'] = $makeInput('100', 'lakh', 'USD');
    $assert($screeningEngine->screen($testPolicy, 'permissible', $mismatchedInputs)->status === 'insufficient', 'A currency mismatch produces insufficient, not pass.');
    $assert($screeningEngine->screen($testPolicy, 'prohibited', $passingInputs)->status === 'failed', 'A prohibited activity review fails before financial ratios.');
    $assert($screeningEngine->screen($testPolicy, 'mixed', $passingInputs)->status === 'insufficient', 'A mixed activity review cannot produce a pass.');

    $scaledInputs = $passingInputs;
    $scaledInputs['test_debt'] = $makeInput('2', 'crore');
    $scaledInputs['test_reference_value'] = $makeInput('200', 'lakh');
    $assert($screeningEngine->screen($testPolicy, 'permissible', $scaledInputs)->status === 'failed', 'Unit normalization compares equivalent crore and lakh base values exactly.');

    $methodologyPayload = $fixture('multibagger_methodology.json');
    $methodologyValidator = new MultibaggerMethodologyValidator();
    $validatedMethodology = $methodologyValidator->validate($methodologyPayload);
    $assert(array_sum(array_column($validatedMethodology['factors'], 'weight_percent')) === 100, 'Multibagger methodology requires weights totaling exactly 100 percent.');
    $assert(strlen($methodologyValidator->hash($methodologyPayload)) === 64, 'Multibagger methodology receives a canonical SHA-256 identity.');
    $wrongDirection = $methodologyPayload;
    $wrongDirection['grade_direction'] = '10_best_1_weakest';
    $wrongDirectionRejected = false;
    try {
        $methodologyValidator->validate($wrongDirection);
    } catch (InvalidArgumentException) {
        $wrongDirectionRejected = true;
    }
    $assert($wrongDirectionRejected, 'Methodology cannot reverse the locked 1-best score direction.');

    $testMethodology = new MultibaggerMethodology(
        id: 1,
        version: $validatedMethodology['version'],
        name: $validatedMethodology['name'],
        effectiveDate: $validatedMethodology['effective_date'],
        verifiedBy: $validatedMethodology['verified_by'],
        verificationNote: $validatedMethodology['verification_note'],
        methodologyHash: $methodologyValidator->hash($methodologyPayload),
        isActive: true,
        definition: $validatedMethodology,
    );
    $scoreEngine = new MultibaggerScoringEngine($decimalMath);
    $factorRows = [
        'financial_strength' => ['grade' => 2, 'evidence_note' => 'Synthetic exchange evidence.', 'evidence_source_url' => 'https://www.nseindia.com/synthetic', 'source_document_id' => null],
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
    $valuationRow = [
        'currency' => 'INR', 'eps' => '4', 'book_value_per_share' => '100', 'dcf_value_per_share' => '25', 'current_price' => '20',
        'dcf_assumptions_note' => 'Synthetic DCF assumptions only.', 'evidence_note' => 'Synthetic valuation evidence.', 'evidence_source_url' => 'https://www.nseindia.com/synthetic',
    ];
    $riskRow = ['market_cap_crore' => '1000', 'red_flags' => [], 'green_flags' => [], 'evidence_note' => 'Synthetic market-cap evidence.', 'evidence_source_url' => 'https://www.nseindia.com/synthetic'];
    $scoreResult = $scoreEngine->score($testMethodology, ['id' => 1, 'status' => 'passed'], $factorRows, $valuationRow, $riskRow);
    $assert($scoreResult->status === 'scored' && $scoreResult->finalScore === 3, 'Equal synthetic factor weights produce an exact rounded score of 3.');
    $assert($scoreResult->alertEligible === true, 'A current Sharia pass and score 3 meet the locked alert gate.');
    $assert($scoreResult->undervaluedByBoth === true, 'Undervalued is true only when current price is within both Graham and DCF values.');
    $assert($scoreEngine->score($testMethodology, null, $factorRows, $valuationRow, $riskRow)->status === 'insufficient', 'Missing Sharia pass prevents multibagger scoring.');
    $supersededMacro = $factorRows;
    $supersededMacro['macro_tailwind']['government_review_status'] = 'superseded';
    $assert($scoreEngine->score($testMethodology, ['id' => 1, 'status' => 'passed'], $supersededMacro, $valuationRow, $riskRow)->status === 'insufficient', 'A superseded government review invalidates the macro factor for a new score.');

    $microRisk = $riskRow;
    $microRisk['market_cap_crore'] = '100';
    $microRisk['red_flags'] = ['synthetic_red'];
    $penalized = $scoreEngine->score($testMethodology, ['id' => 1, 'status' => 'passed'], $factorRows, $valuationRow, $microRisk);
    $assert($penalized->finalScore === 5 && $penalized->alertEligible === false, 'Microcap red flag adds two points and closes the alert gate.');
    $valuationDisagrees = $valuationRow;
    $valuationDisagrees['current_price'] = '26';
    $assert($scoreEngine->score($testMethodology, ['id' => 1, 'status' => 'passed'], $factorRows, $valuationDisagrees, $riskRow)->undervaluedByBoth === false, 'One valuation method disagreeing prevents the undervalued label.');
    $assert(ResearchEvidenceUrl::isAllowed('https://www.pib.gov.in/synthetic', true), 'Government macro source allowlist accepts PIB HTTPS.');
    $assert(!ResearchEvidenceUrl::isAllowed('https://example.com/article', true), 'Government macro source allowlist rejects a media or third-party host.');
}

echo "\n{$passed} passed, {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
