#!/usr/bin/env php
<?php

declare(strict_types=1);

use HalalPulse\Http\CurlHttpClient;
use HalalPulse\Http\HttpRequestPolicy;

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
$rejects = static function (callable $operation): bool {
    try {
        $operation();
        return false;
    } catch (RuntimeException) {
        return true;
    }
};

$policy = new HttpRequestPolicy(['www.nseindia.com', 'nsearchives.nseindia.com']);
$valid = true;
try {
    $policy->assertAllowedUrl('https://www.nseindia.com/api/corporate-announcements?index=equities');
} catch (Throwable) {
    $valid = false;
}
$assert($valid, 'An exact allowlisted HTTPS URL on port 443 is accepted.');
$assert($rejects(static fn () => $policy->assertAllowedUrl('http://www.nseindia.com/api')), 'Plain HTTP is rejected.');
$assert($rejects(static fn () => $policy->assertAllowedUrl('https://example.com/api')), 'A third-party hostname is rejected.');
$assert($rejects(static fn () => $policy->assertAllowedUrl('https://www.nseindia.com:8443/api')), 'A nonstandard HTTPS port is rejected.');
$assert($rejects(static fn () => $policy->assertAllowedUrl('https://user:pass@www.nseindia.com/api')), 'Credential-bearing URLs are rejected.');
$assert($rejects(static fn () => $policy->assertAllowedUrl('https://www.nseindia.com/api#fragment')), 'Server-side request fragments are rejected.');
$assert($rejects(static fn () => $policy->assertAllowedUrl("https://www.nseindia.com/api\nInjected")), 'Control characters in URLs are rejected.');
$assert($rejects(static fn () => new HttpRequestPolicy(['*.nseindia.com'])), 'Wildcard allowlist entries are rejected.');
$assert($rejects(static fn () => new HttpRequestPolicy(['localhost'])), 'Single-label internal hostnames are rejected.');
$assert($rejects(static fn () => new HttpRequestPolicy(['www.nseindia.com.'])), 'Trailing-dot hostname aliases are rejected.');

$headerLines = $policy->headerLines([
    'Accept' => ' application/json ',
    'X-Research-Client' => 'HalalPulse',
]);
$assert($headerLines === ['Accept: application/json', 'X-Research-Client: HalalPulse'], 'Valid request headers are normalized into deterministic lines.');
$assert($rejects(static fn () => $policy->headerLines(['X-Test' => "safe\r\nInjected: value"])), 'CRLF request-header injection is rejected.');
$assert($rejects(static fn () => $policy->headerLines(['Bad Header' => 'value'])), 'Invalid request-header names are rejected.');
$assert($rejects(static fn () => $policy->headerLines(['X-Test' => str_repeat('x', 8193)])), 'Oversized request-header values are rejected.');
$tooManyHeaders = [];
for ($index = 0; $index < 51; $index++) {
    $tooManyHeaders['X-Test-' . $index] = 'value';
}
$assert($rejects(static fn () => $policy->headerLines($tooManyHeaders)), 'Excessive request-header counts are rejected.');

$clientCreated = true;
try {
    new CurlHttpClient(['www.nseindia.com'], 20, 'HalalPulse/Test', 1024, 4096);
} catch (Throwable) {
    $clientCreated = false;
}
$assert($clientCreated, 'The bounded shared cURL client accepts safe operational limits.');
$assert($rejects(static fn () => new CurlHttpClient(['www.nseindia.com'], 0, 'HalalPulse/Test')), 'A zero request timeout is rejected.');
$assert($rejects(static fn () => new CurlHttpClient(['www.nseindia.com'], 121, 'HalalPulse/Test')), 'An excessive request timeout is rejected.');
$assert($rejects(static fn () => new CurlHttpClient(['www.nseindia.com'], 20, "Unsafe\nAgent")), 'User-agent header injection is rejected.');
$assert($rejects(static fn () => new CurlHttpClient(['www.nseindia.com'], 20, 'HalalPulse/Test', 67_108_865)), 'Response-body limits above 64 MiB are rejected.');
$assert($rejects(static fn () => new CurlHttpClient(['www.nseindia.com'], 20, 'HalalPulse/Test', 1024, 131_073)), 'Response-header limits above 128 KiB are rejected.');

echo "\n{$passed} passed, {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
