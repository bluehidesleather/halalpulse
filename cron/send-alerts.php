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

$config = require dirname(__DIR__) . '/app/bootstrap.php';
$alertConfig = AlertConfiguration::fromConfig($config);
if (!$alertConfig->enabled) {
    echo json_encode(['status' => 'not_configured', 'message' => 'Telegram alert delivery is disabled.'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    exit(0);
}

try {
    $alertConfig->assertReady();
    $appKey = $config->requireString('security.app_key');
    $pdo = Database::connect($config);
    $repository = new AlertRepository($pdo);
    $recipientRepository = new AlertRecipientRepository($pdo, new AlertRecipientCrypto($appKey));
    $recipients = $recipientRepository->activeTelegram($alertConfig->recipientLimit);
    if ($recipients === []) {
        echo json_encode(['status' => 'no_recipients', 'message' => 'No active consented Telegram recipients are registered.'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
        exit(0);
    }
    $repository->recoverStaleReservations();
    $dispatcher = new AlertDispatcher(
        $repository,
        new AlertMessageBuilder($alertConfig->appBaseUrl),
        new TelegramBotClient($alertConfig),
        new JsonLogger($config->requireString('app.log_path')),
    );
    $results = [];
    foreach ($recipients as $recipient) {
        foreach ($dispatcher->dispatch($recipient, $alertConfig->batchSize) as $result) {
            $results[] = $result + ['recipient_id' => $recipient->id];
        }
    }
    $failed = array_filter($results, static fn (array $result): bool => $result['status'] !== 'accepted');
    echo json_encode([
        'status' => $failed === [] ? ($results === [] ? 'no_candidates' : 'succeeded') : 'failed',
        'recipients_checked' => count($recipients),
        'deliveries' => $results,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
    exit($failed === [] ? 0 : 1);
} catch (Throwable $exception) {
    (new JsonLogger($config->requireString('app.log_path')))->error('Alert command failed before delivery.', ['exception' => $exception::class]);
    fwrite(STDERR, json_encode(['status' => 'failed', 'message' => 'Telegram alert configuration or database gate failed.'], JSON_THROW_ON_ERROR) . PHP_EOL);
    exit(1);
}
