#!/usr/bin/env php
<?php

declare(strict_types=1);

use HalalPulse\Config;
use HalalPulse\Operations\BackupEncryptor;
use HalalPulse\Operations\BackupService;
use HalalPulse\Support\CliSecretPrompt;
use HalalPulse\Support\PrivateConfigEditor;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

$passphrase = '';
try {
    fwrite(STDOUT, "HalalPulse encrypted backup activation\n");
    fwrite(STDOUT, "The passphrase is written only to ignored config/config.local.php and is never printed.\n\n");
    $passphrase = CliSecretPrompt::readConfirmed('Backup encryption passphrase', 24, 1024);

    (new PrivateConfigEditor(HALALPULSE_ROOT))->apply([
        'backups' => [
            'enabled' => true,
            'storage_path' => HALALPULSE_ROOT . '/storage/backups',
            'retention_days' => 14,
            'maximum_age_hours' => 30,
            'encryption_passphrase' => $passphrase,
            'mysqldump_binary' => HALALPULSE_ROOT . '/bin/mysqldump-no-tablespaces',
            'tar_binary' => '/usr/bin/tar',
            'include_paths' => [
                'config/config.local.php',
                'storage/documents',
                'storage/xbrl',
            ],
        ],
    ]);

    $values = require HALALPULSE_ROOT . '/config/config.local.php';
    if (!is_array($values)) {
        throw new RuntimeException('Updated private configuration did not return an array.');
    }
    $config = new Config($values);
    $encryptor = new BackupEncryptor();
    $service = new BackupService($config, $encryptor, HALALPULSE_ROOT);
    $result = $service->create();
    if (!$encryptor->verify(
        (string) $result['path'],
        $passphrase,
        (string) $result['plaintext_sha256'],
    )) {
        throw new RuntimeException('Authenticated verification of the new backup failed.');
    }

    fwrite(STDOUT, json_encode([
        'status' => 'succeeded',
        'filename' => $result['filename'],
        'created_at' => $result['created_at'],
        'bytes' => $result['bytes'],
        'encrypted_sha256' => $result['encrypted_sha256'],
        'authenticated_decryption_checked' => true,
        'next_step' => 'Store this passphrase separately in a password manager and configure the daily backup cron.',
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
} catch (Throwable $exception) {
    fwrite(STDERR, "Backup activation failed: {$exception->getMessage()}\n");
    exit(1);
} finally {
    CliSecretPrompt::wipe($passphrase);
}
