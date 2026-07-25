#!/usr/bin/env php
<?php

declare(strict_types=1);

use HalalPulse\Alerts\AlertActivationOrigin;
use HalalPulse\Alerts\AlertConfiguration;
use HalalPulse\Config;
use HalalPulse\Support\CliSecretPrompt;
use HalalPulse\Support\PrivateConfigEditor;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

$token = '';
try {
    if (!function_exists('stream_isatty') || !stream_isatty(STDIN)) {
        throw new RuntimeException('A private interactive terminal is required.');
    }

    fwrite(STDOUT, "HalalPulse Telegram preparation\n");
    fwrite(STDOUT, "This stores the bot token only in ignored private configuration. Alerts remain disabled.\n\n");
    fwrite(STDOUT, 'Permanent HTTPS application origin (for example https://halalpulse.example): ');
    $baseUrl = fgets(STDIN, 2050);
    if (!is_string($baseUrl)) {
        throw new RuntimeException('Unable to read the application origin.');
    }
    $baseUrl = rtrim($baseUrl, "\r\n/");
    AlertActivationOrigin::assertPermanent($baseUrl);

    $token = CliSecretPrompt::readConfirmed('Telegram bot token', 37, 128);
    $current = require HALALPULSE_ROOT . '/config/config.local.php';
    if (!is_array($current)) {
        throw new RuntimeException('Private configuration must return an array.');
    }
    $candidate = array_replace_recursive($current, [
        'alerts' => [
            'enabled' => false,
            'channel' => 'telegram',
            'app_base_url' => $baseUrl,
            'telegram' => [
                'bot_token' => $token,
            ],
        ],
    ]);
    $configuration = AlertConfiguration::fromConfig(new Config($candidate));
    $configuration->assertTransportReady();

    (new PrivateConfigEditor(HALALPULSE_ROOT))->apply([
        'alerts' => [
            'enabled' => false,
            'channel' => 'telegram',
            'app_base_url' => $baseUrl,
            'telegram' => [
                'bot_token' => $token,
            ],
        ],
    ]);

    fwrite(STDOUT, "\nTelegram private configuration prepared. Automatic alerts remain disabled.\n");
    fwrite(STDOUT, "Next: open the bot, send /start, then run php cron/discover-telegram-chats.php.\n");
    fwrite(STDOUT, "Register the consenting chat in Alerts, perform a manual delivery test, and enable only after the test succeeds.\n");
} catch (Throwable $exception) {
    fwrite(STDERR, "Telegram preparation failed: {$exception->getMessage()}\n");
    exit(1);
} finally {
    CliSecretPrompt::wipe($token);
}
