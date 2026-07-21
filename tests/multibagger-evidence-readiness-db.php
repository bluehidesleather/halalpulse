#!/usr/bin/env php
<?php

declare(strict_types=1);

use HalalPulse\Database;
use HalalPulse\Multibagger\MultibaggerRepository;

$config = require dirname(__DIR__) . '/app/bootstrap.php';
$pdo = Database::connect($config);
$companyId = (int) $pdo->query("SELECT id FROM companies WHERE exchange='NSE' AND symbol='BACKFILL' LIMIT 1")->fetchColumn();
if ($companyId < 1) {
    fwrite(STDERR, "[FAIL] Synthetic backfill company is missing.\n");
    exit(1);
}

$periods = (new MultibaggerRepository($pdo))->periods($companyId);
if (!in_array('2026-06-30', $periods, true)) {
    fwrite(STDERR, "[FAIL] Structured financial-result period was not exposed to the multibagger review.\n");
    exit(1);
}

if ($periods !== array_values(array_unique($periods))) {
    fwrite(STDERR, "[FAIL] Multibagger reporting periods are not unique.\n");
    exit(1);
}

$sorted = $periods;
rsort($sorted);
if ($periods !== $sorted) {
    fwrite(STDERR, "[FAIL] Multibagger reporting periods are not newest first.\n");
    exit(1);
}

fwrite(STDOUT, "[PASS] Multibagger review exposes unique real reporting periods newest first.\n");
