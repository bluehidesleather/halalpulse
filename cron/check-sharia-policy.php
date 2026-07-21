#!/usr/bin/env php
<?php

declare(strict_types=1);

use HalalPulse\Sharia\ShariaPolicyReadinessInspector;
use HalalPulse\Sharia\ShariaPolicyValidator;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

$path = $argv[1] ?? (HALALPULSE_ROOT . '/config/sharia-policy.local.json');
if (!is_string($path) || trim($path) === '') {
    fwrite(STDERR, "Usage: php cron/check-sharia-policy.php /absolute/path/to/policy.json\n");
    exit(2);
}

$realPath = realpath($path);
if ($realPath === false || !is_file($realPath) || !is_readable($realPath)) {
    fwrite(STDERR, "Policy file is not readable: {$path}\n");
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

    $inspection = (new ShariaPolicyReadinessInspector())->inspect($payload);
    foreach ($inspection['errors'] as $error) {
        fwrite(STDOUT, "[BLOCKED] {$error}\n");
    }
    foreach ($inspection['warnings'] as $warning) {
        fwrite(STDOUT, "[WARNING] {$warning}\n");
    }

    if (!$inspection['ready']) {
        fwrite(STDOUT, "\nPolicy is not ready for activation. No database changes were made.\n");
        exit(1);
    }

    $validator = new ShariaPolicyValidator();
    $validated = $validator->validate($payload);
    fwrite(STDOUT, "[READY] Policy passes activation validation.\n");
    fwrite(STDOUT, "Version: {$validated['version']}\n");
    fwrite(STDOUT, "Authority: {$validated['authority_name']} {$validated['authority_standard']}\n");
    fwrite(STDOUT, 'Required inputs: ' . implode(', ', array_values(array_unique(array_merge(
        array_column($validated['ratios'], 'numerator_key'),
        array_column($validated['ratios'], 'denominator_key'),
    )))) . "\n");
    fwrite(STDOUT, "No database changes were made. Run install-sharia-policy.php only after final approval.\n");
} catch (Throwable $exception) {
    fwrite(STDERR, "Policy check failed: {$exception->getMessage()}\n");
    exit(1);
}
