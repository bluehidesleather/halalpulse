#!/usr/bin/env php
<?php

declare(strict_types=1);

use HalalPulse\Database;
use HalalPulse\Sharia\ShariaEvidenceReadinessRepository;

$config = require dirname(__DIR__) . '/app/bootstrap.php';
$pdo = Database::connect($config);
$companyId = (int) $pdo->query("SELECT id FROM companies WHERE exchange = 'NSE' AND symbol = 'BACKFILL' LIMIT 1")->fetchColumn();
if ($companyId < 1) {
    fwrite(STDERR, "Synthetic backfill company is missing.\n");
    exit(1);
}

$repository = new ShariaEvidenceReadinessRepository($pdo);
$periods = $repository->periods($companyId);
if ($periods !== ['2026-06-30']) {
    fwrite(STDERR, 'Unexpected evidence periods: ' . json_encode($periods, JSON_THROW_ON_ERROR) . "\n");
    exit(1);
}

$candidates = $repository->pendingCandidatesForPeriod($companyId, '2026-06-30');
if (count($candidates) !== 1 || ($candidates[0]['metric_key'] ?? null) !== 'total_revenue') {
    fwrite(STDERR, 'Expected one pending total_revenue candidate for the stored reporting period.' . "\n");
    exit(1);
}

echo "[PASS] Stored financial-result and candidate periods feed the Sharia readiness selector.\n";
