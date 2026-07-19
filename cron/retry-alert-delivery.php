#!/usr/bin/env php
<?php

declare(strict_types=1);

use HalalPulse\Alerts\AlertConfiguration;
use HalalPulse\Alerts\AlertDispatcher;
use HalalPulse\Alerts\AlertMessageBuilder;
use HalalPulse\Alerts\AlertRecipientCrypto;
use HalalPulse\Alerts\AlertRecipientRepository;
use HalalPulse\Alerts\AlertRepository;
use HalalPulse\Alerts\TelegramBotClient;
use HalalPulse\Database;
use HalalPulse\Support\JsonLogger;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if ($argc !== 3 || preg_match('/^[1-9][0-9]*$/D', (string) $argv[1]) !== 1 || $argv[2] !== '--confirm-no-message-sent') {
    fwrite(STDERR, "Usage: php cron/retry-alert-delivery.php DELIVERY_ID --confirm-no-message-sent\n");
    fwrite(STDERR, "For an unknown delivery, inspect the Telegram chat first. Retrying without that check can send a duplicate.\n");
    exit(2);
}

$config = require dirname(__DIR__) . '/app/bootstrap.php';
$alertConfig = AlertConfiguration::fromConfig($config);
try {
    $alertConfig->assertReady();
    $appKey = $config->requireString('security.app_key');
    $pdo = Database::connect($config);
    $repository = new AlertRepository($pdo);
    $recipientRepository = new AlertRecipientRepository($pdo, new AlertRecipientCrypto($appKey));
    $delivery = $repository->delivery((int) $argv[1]);
    if ($delivery === null || (string) $delivery['channel'] !== 'telegram' || (int) ($delivery['recipient_id'] ?? 0) < 1) {
        throw new InvalidArgumentException('Current Telegram alert delivery was not found.');
    }
    $recipient = $recipientRepository->activeTelegramById((int) $delivery['recipient_id']);
    if ($recipient === null || !hash_equals((string) $delivery['recipient_hash'], $recipient->recipientHash)) {
        throw new InvalidArgumentException('The Telegram recipient is no longer active or its identity changed.');
    }
    $candidate = $repository->currentCandidateByScore((int) $delivery['score_id']);
    if ($candidate === null) {
        throw new InvalidArgumentException('The score is no longer a current dispatchable candidate.');
    }
    $builder = new AlertMessageBuilder($alertConfig->appBaseUrl);
    $body = $builder->build($candidate);
    $reservation = $repository->beginManualRetry((int) $delivery['id'], hash('sha256', $body));
    $result = (new AlertDispatcher(
        $repository,
        $builder,
        new TelegramBotClient($alertConfig),
        new JsonLogger($config->requireString('app.log_path')),
    ))->sendReserved($candidate, $body, $reservation, $recipient);
    echo json_encode(['status' => $result['status'], 'delivery' => $result], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    exit($result['status'] === 'accepted' ? 0 : 1);
} catch (Throwable $exception) {
    fwrite(STDERR, json_encode(['status' => 'failed', 'message' => $exception->getMessage()], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    exit(1);
}
