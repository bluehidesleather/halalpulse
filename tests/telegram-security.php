#!/usr/bin/env php
<?php

declare(strict_types=1);

use HalalPulse\Alerts\AlertConfiguration;
use HalalPulse\Alerts\TelegramApiException;
use HalalPulse\Alerts\TelegramRequestPolicy;

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
    } catch (InvalidArgumentException|TelegramApiException) {
        return true;
    }
};

$token = '123456789' . ':' . str_repeat('A', 35);
$makeConfiguration = static function (array $overrides = []) use ($token): AlertConfiguration {
    $values = array_merge([
        'enabled' => true,
        'channel' => 'telegram',
        'batchSize' => 1,
        'recipientLimit' => 1,
        'appBaseUrl' => 'https://halalpulse.example',
        'botToken' => $token,
        'timeoutSeconds' => 20,
        'maxRequestBytes' => 16_384,
        'maxResponseBytes' => 1_048_576,
        'maxHeaderBytes' => 65_536,
    ], $overrides);

    return new AlertConfiguration(...$values);
};

$valid = true;
try {
    $makeConfiguration()->assertReady();
} catch (Throwable) {
    $valid = false;
}
$assert($valid, 'A complete Telegram transport configuration passes validation.');
$assert($rejects(static fn () => $makeConfiguration(['channel' => 'email'])->assertTransportReady()), 'Unsupported alert channels are rejected.');
$assert($rejects(static fn () => $makeConfiguration(['botToken' => 'invalid'])->assertTransportReady()), 'Invalid Telegram bot-token shapes are rejected.');
$assert($rejects(static fn () => $makeConfiguration(['appBaseUrl' => 'http://halalpulse.example'])->assertTransportReady()), 'Plain-HTTP application origins are rejected.');
$assert($rejects(static fn () => $makeConfiguration(['appBaseUrl' => 'https://halalpulse.example/private'])->assertTransportReady()), 'Application URLs containing a path are rejected.');
$assert($rejects(static fn () => $makeConfiguration(['appBaseUrl' => 'https://halalpulse.example?source=alert'])->assertTransportReady()), 'Application URLs containing a query are rejected.');
$assert($rejects(static fn () => $makeConfiguration(['appBaseUrl' => 'https://halalpulse.example#section'])->assertTransportReady()), 'Application URLs containing a fragment are rejected.');
$assert($rejects(static fn () => $makeConfiguration(['appBaseUrl' => 'https://halalpulse.example:443'])->assertTransportReady()), 'Explicit application URL ports are rejected.');
$credentialOrigin = 'https://' . 'synthetic-user' . ':' . 'synthetic-pass' . '@halalpulse.example';
$assert($rejects(static fn () => $makeConfiguration(['appBaseUrl' => $credentialOrigin])->assertTransportReady()), 'Credential-bearing application origins are rejected.');
$assert($rejects(static fn () => $makeConfiguration(['appBaseUrl' => 'https://127.0.0.1'])->assertTransportReady()), 'IP-literal application origins are rejected.');
$assert($rejects(static fn () => $makeConfiguration(['appBaseUrl' => 'https://localhost'])->assertTransportReady()), 'Single-label internal application origins are rejected.');
$assert($rejects(static fn () => $makeConfiguration(['timeoutSeconds' => 0])->assertTransportReady()), 'Zero Telegram request timeouts are rejected.');
$assert($rejects(static fn () => $makeConfiguration(['timeoutSeconds' => 61])->assertTransportReady()), 'Excessive Telegram request timeouts are rejected.');
$assert($rejects(static fn () => $makeConfiguration(['maxRequestBytes' => 1023])->assertTransportReady()), 'Telegram request limits below 1 KiB are rejected.');
$assert($rejects(static fn () => $makeConfiguration(['maxRequestBytes' => 65_537])->assertTransportReady()), 'Telegram request limits above 64 KiB are rejected.');
$assert($rejects(static fn () => $makeConfiguration(['maxResponseBytes' => 4_194_305])->assertTransportReady()), 'Telegram response limits above 4 MiB are rejected.');
$assert($rejects(static fn () => $makeConfiguration(['maxHeaderBytes' => 131_073])->assertTransportReady()), 'Telegram response-header limits above 128 KiB are rejected.');

$policy = new TelegramRequestPolicy($token, 1024);
$assert(str_ends_with($policy->endpoint('sendMessage'), '/sendMessage'), 'The sendMessage API method is explicitly allowed.');
$assert(str_ends_with($policy->endpoint('getUpdates'), '/getUpdates'), 'The getUpdates API method is explicitly allowed.');
$assert($rejects(static fn () => $policy->endpoint('../getMe')), 'Unapproved Telegram API methods and path traversal are rejected.');
$encoded = $policy->encodeParameters(['chat_id' => '123', 'text' => 'Synthetic message']);
$assert(str_contains($encoded, 'Synthetic message'), 'Telegram parameters are encoded as bounded JSON.');
$assert($rejects(static fn () => $policy->encodeParameters(['text' => str_repeat('x', 2000)])), 'Encoded Telegram requests above the configured limit are rejected.');
$assert($rejects(static fn () => $policy->encodeParameters(['text' => "\xB1\x31"])), 'Invalid UTF-8 Telegram parameters fail closed.');
$assert($rejects(static fn () => new TelegramRequestPolicy('invalid', 1024)), 'The standalone Telegram request policy validates its token.');
$assert($rejects(static fn () => new TelegramRequestPolicy($token, 1023)), 'The standalone Telegram request policy validates its request-size floor.');

$description = "Provider exposed {$token}\r\nwith\tcontrol bytes";
$safeDescription = $policy->safeProviderDescription($description);
$assert(!str_contains($safeDescription, $token), 'Provider error descriptions redact the private bot token.');
$assert(!str_contains($safeDescription, "\r") && !str_contains($safeDescription, "\n") && !str_contains($safeDescription, "\t"), 'Provider error descriptions collapse control whitespace.');
$assert(mb_strlen($policy->safeProviderDescription(str_repeat('x', 800))) === 500, 'Provider error descriptions are length-bounded.');
$assert($policy->safeProviderDescription("\x01\x02") === 'Telegram returned an unspecified provider error.', 'Empty sanitized provider descriptions receive a safe generic message.');

echo "\n{$passed} passed, {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
