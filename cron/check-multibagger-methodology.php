#!/usr/bin/env php
<?php

declare(strict_types=1);

use HalalPulse\Multibagger\MultibaggerMethodologyReadinessInspector;
use HalalPulse\Multibagger\MultibaggerMethodologyValidator;

require dirname(__DIR__) . '/app/bootstrap.php';

$path = $argv[1] ?? (HALALPULSE_ROOT . '/config/multibagger-methodology.local.json');
if (!is_string($path) || trim($path) === '') {
    fwrite(STDERR, "Usage: php cron/check-multibagger-methodology.php /absolute/path/to/reviewed-methodology.json\n");
    exit(2);
}

$realPath = realpath($path);
if ($realPath === false || !is_file($realPath) || !is_readable($realPath)) {
    fwrite(STDERR, "Methodology file is not readable: {$path}\n");
    exit(2);
}

$size = filesize($realPath);
if (!is_int($size) || $size < 2 || $size > 262144) {
    fwrite(STDERR, "Methodology file must contain between 2 bytes and 256 KiB.\n");
    exit(2);
}

try {
    $json = file_get_contents($realPath);
    if (!is_string($json)) {
        throw new RuntimeException('Unable to read methodology file.');
    }

    $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($payload)) {
        throw new RuntimeException('Methodology JSON must contain an object.');
    }

    $inspector = new MultibaggerMethodologyReadinessInspector();
    $review = $inspector->inspect($payload, false);
    foreach ($review['warnings'] as $warning) {
        fwrite(STDOUT, "[WARNING] {$warning}\n");
    }
    if ($review['errors'] !== []) {
        foreach ($review['errors'] as $error) {
            fwrite(STDOUT, "[BLOCKED] {$error}\n");
        }
        fwrite(STDOUT, "No database changes were made.\n");
        exit(1);
    }

    $approvalReady = $inspector->inspect($payload, true);
    if ($approvalReady['errors'] !== []) {
        foreach ($approvalReady['errors'] as $error) {
            fwrite(STDOUT, "[BLOCKED] {$error}\n");
        }
        fwrite(STDOUT, "The methodology structure is reviewable, but it is not approved for activation. No database changes were made.\n");
        exit(1);
    }

    $validator = new MultibaggerMethodologyValidator();
    $validated = $validator->validate($payload);
    fwrite(STDOUT, "[READY] Multibagger methodology {$validated['version']} passed the non-mutating readiness check.\n");
    fwrite(STDOUT, "SHA-256: {$validator->hash($validated)}\n");
    fwrite(STDOUT, "No database changes were made.\n");
} catch (Throwable $exception) {
    fwrite(STDERR, "Methodology readiness check failed: {$exception->getMessage()}\n");
    exit(1);
}
