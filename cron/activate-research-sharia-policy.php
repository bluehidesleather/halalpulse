#!/usr/bin/env php
<?php

declare(strict_types=1);

use HalalPulse\Database;
use HalalPulse\Sharia\ShariaPolicyInstaller;
use HalalPulse\Sharia\ShariaPolicyReadinessInspector;
use HalalPulse\Sharia\ShariaPolicyValidator;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$config = require dirname(__DIR__) . '/app/bootstrap.php';
$acknowledged = in_array('--acknowledge-research-only', $argv, true);
if (!$acknowledged) {
    fwrite(STDERR, "Research policy activation requires explicit acknowledgement.\n\n");
    fwrite(STDERR, "This policy produces research pass/fail results only. It is not a fatwa, not independent Sharia certification, and not financial advice.\n");
    fwrite(STDERR, "Run again with: --acknowledge-research-only\n");
    exit(2);
}

$path = HALALPULSE_ROOT . '/config/sharia-research-policy.json';
try {
    $json = file_get_contents($path);
    if (!is_string($json)) {
        throw new RuntimeException('Unable to read the versioned research policy.');
    }
    $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($payload)) {
        throw new RuntimeException('Research policy JSON must contain an object.');
    }
    $payload['approved_for_use'] = true;

    $inspection = (new ShariaPolicyReadinessInspector())->inspect($payload);
    if (!$inspection['ready']) {
        foreach ($inspection['errors'] as $error) {
            fwrite(STDERR, "[BLOCKED] {$error}\n");
        }
        throw new RuntimeException('The versioned research policy did not pass readiness validation.');
    }

    $result = (new ShariaPolicyInstaller(
        Database::connect($config),
        new ShariaPolicyValidator(),
    ))->installAndActivate($payload);

    fwrite(STDOUT, "Activated research Sharia policy {$result['version']}\n");
    fwrite(STDOUT, "SHA-256: {$result['policy_hash']}\n");
    fwrite(STDOUT, "Assurance: research only\n");
    fwrite(STDOUT, "Disclaimer: {$payload['disclaimer']}\n");
    fwrite(STDOUT, "Previous policy records remain stored but inactive.\n");
} catch (Throwable $exception) {
    fwrite(STDERR, "Research policy activation failed: {$exception->getMessage()}\n");
    exit(1);
}
