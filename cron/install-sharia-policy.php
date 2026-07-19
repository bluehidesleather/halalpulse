#!/usr/bin/env php
<?php

declare(strict_types=1);

use HalalPulse\Database;
use HalalPulse\Sharia\ShariaPolicyInstaller;
use HalalPulse\Sharia\ShariaPolicyValidator;

$config = require dirname(__DIR__) . '/app/bootstrap.php';
$path = $argv[1] ?? (HALALPULSE_ROOT . '/config/sharia-policy.local.json');

if (!is_string($path) || trim($path) === '') {
    fwrite(STDERR, "Usage: php cron/install-sharia-policy.php /absolute/path/to/verified-policy.json\n");
    exit(2);
}

$realPath = realpath($path);
if ($realPath === false || !is_file($realPath) || !is_readable($realPath)) {
    fwrite(STDERR, "Policy file is not readable: {$path}\n");
    exit(2);
}

$size = filesize($realPath);
if (!is_int($size) || $size < 2 || $size > 131072) {
    fwrite(STDERR, "Policy file must contain between 2 bytes and 128 KiB.\n");
    exit(2);
}

try {
    $json = file_get_contents($realPath);
    if (!is_string($json)) {
        throw new RuntimeException('Unable to read policy file.');
    }

    $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($payload)) {
        throw new RuntimeException('Policy JSON must contain an object.');
    }

    $result = (new ShariaPolicyInstaller(
        Database::connect($config),
        new ShariaPolicyValidator(),
    ))->installAndActivate($payload);

    fwrite(STDOUT, "Activated Sharia policy {$result['version']}\n");
    fwrite(STDOUT, "SHA-256: {$result['policy_hash']}\n");
    fwrite(STDOUT, "Previous policies remain stored but inactive.\n");
} catch (Throwable $exception) {
    fwrite(STDERR, "Policy activation failed: {$exception->getMessage()}\n");
    exit(1);
}
