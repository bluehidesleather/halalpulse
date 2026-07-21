#!/usr/bin/env php
<?php

declare(strict_types=1);

use HalalPulse\Operations\BackupEncryptor;
use HalalPulse\Operations\BackupService;

$config = require dirname(__DIR__) . '/app/bootstrap.php';

try {
    $service = new BackupService($config, new BackupEncryptor(), HALALPULSE_ROOT);
    $result = $service->create();
    fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
} catch (Throwable $exception) {
    fwrite(STDERR, json_encode([
        'status' => 'failed',
        'error' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
    exit(1);
}
