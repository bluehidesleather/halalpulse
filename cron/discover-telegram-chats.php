#!/usr/bin/env php
<?php

declare(strict_types=1);

use HalalPulse\Alerts\AlertConfiguration;
use HalalPulse\Alerts\TelegramBotClient;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$config = require dirname(__DIR__) . '/app/bootstrap.php';
try {
    $alertConfig = AlertConfiguration::fromConfig($config);
    $chats = (new TelegramBotClient($alertConfig))->discoverChats();
    echo json_encode([
        'status' => $chats === [] ? 'no_chats' : 'succeeded',
        'instruction' => $chats === [] ? 'Send /start to the bot, then run this command again.' : 'Register only the intended consenting chat on the authenticated Alerts page.',
        'chats' => $chats,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, json_encode(['status' => 'failed', 'message' => 'Telegram discovery failed. Verify the private bot token and inspect the private log.'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    exit(1);
}
