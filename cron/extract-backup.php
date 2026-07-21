#!/usr/bin/env php
<?php

declare(strict_types=1);

use HalalPulse\Operations\BackupEncryptor;
use HalalPulse\Operations\BackupRestoreService;

$config = require dirname(__DIR__) . '/app/bootstrap.php';
$backupPath = $argv[1] ?? '';
$destination = $argv[2] ?? '';
$confirmed = in_array('--confirm-isolated-extraction', $argv, true);

if (!is_string($backupPath) || trim($backupPath) === '' || !is_string($destination) || trim($destination) === '' || !$confirmed) {
    fwrite(STDERR, "Usage: php cron/extract-backup.php /private/path/file.hpbak /isolated/empty/destination --confirm-isolated-extraction\n");
    fwrite(STDERR, "This verifies and extracts files only. It never imports the database into the live application.\n");
    exit(2);
}

try {
    $service = new BackupRestoreService($config, new BackupEncryptor(), HALALPULSE_ROOT);
    $result = $service->extract($backupPath, $destination);
    fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
} catch (Throwable $exception) {
    fwrite(STDERR, json_encode([
        'status' => 'failed',
        'error' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
    exit(1);
}
