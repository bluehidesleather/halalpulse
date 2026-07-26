#!/usr/bin/env php
<?php

declare(strict_types=1);

use HalalPulse\Database;
use HalalPulse\Sharia\LegacyShariaCandidateMigrator;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$config = require dirname(__DIR__) . '/app/bootstrap.php';

try {
    $result = (new LegacyShariaCandidateMigrator(Database::connect($config)))->migrate();
    $status = $result['remaining_pending'] === 0 && $result['legacy_current_inputs'] === 0
        ? 'succeeded'
        : 'blocked';

    fwrite(STDOUT, json_encode([
        'status' => $status,
        ...$result,
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");

    if ($result['remaining_pending'] > 0) {
        fwrite(STDERR, "Pending total_revenue candidates remain and must be inspected before research-policy activation.\n");
    }
    if ($result['legacy_current_inputs'] > 0) {
        fwrite(STDERR, "Current accepted total_revenue inputs exist. Review and replace them with exact total_income evidence before activation.\n");
    }

    exit($status === 'succeeded' ? 0 : 1);
} catch (Throwable $exception) {
    fwrite(STDERR, "Sharia total-income candidate migration failed: {$exception->getMessage()}\n");
    exit(2);
}
