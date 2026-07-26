#!/usr/bin/env php
<?php

declare(strict_types=1);

use HalalPulse\Database;
use HalalPulse\Sharia\LegacyShariaCandidateMigrator;

$config = require dirname(__DIR__) . '/app/bootstrap.php';
$pdo = Database::connect($config);

$companyId = (int) $pdo->query("SELECT id FROM companies WHERE exchange='NSE' AND symbol='MIGRATE' LIMIT 1")->fetchColumn();
if ($companyId < 1) {
    fwrite(STDERR, "[FAIL] Synthetic migration company is missing.\n");
    exit(1);
}

$failed = 0;
$assert = static function (bool $condition, string $message) use (&$failed): void {
    if ($condition) {
        fwrite(STDOUT, "[PASS] {$message}\n");
        return;
    }

    $failed++;
    fwrite(STDERR, "[FAIL] {$message}\n");
};

$migrator = new LegacyShariaCandidateMigrator($pdo);
$first = $migrator->migrate();
$assert($first['migrated'] === 1, 'One direct legacy Income candidate migrates to total_income.');
$assert($first['duplicate_retired'] === 1, 'One duplicate direct legacy candidate is retired without violating the unique key.');
$assert($first['fallback_rejected'] === 1, 'One revenue-only legacy fallback is rejected.');
$assert($first['remaining_pending'] === 0, 'No pending total_revenue candidates remain.');
$assert($first['legacy_current_inputs'] === 0, 'No current accepted total_revenue input blocks activation.');

$rows = $pdo->query(
    <<<'SQL'
    SELECT nii.source_filename, sic.metric_key, sic.source_fact_name, sic.review_status, sic.reviewed_at
    FROM sharia_input_candidates sic
    INNER JOIN nse_integrated_feed_items nii ON nii.id = sic.integrated_item_id
    WHERE sic.company_id = (SELECT id FROM companies WHERE exchange='NSE' AND symbol='MIGRATE')
    ORDER BY nii.source_filename, sic.id
    SQL,
)->fetchAll();

$byFile = [];
foreach ($rows as $row) {
    $byFile[(string) $row['source_filename']][] = $row;
}

$direct = $byFile['INTEGRATED_FILING_INDAS_MIGRATE_DIRECT_WEB.xml'][0] ?? null;
$assert(
    is_array($direct)
    && $direct['metric_key'] === 'total_income'
    && $direct['source_fact_name'] === 'Income'
    && $direct['review_status'] === 'pending',
    'Direct Income evidence remains pending under the exact total_income key.',
);

$fallback = $byFile['INTEGRATED_FILING_INDAS_MIGRATE_FALLBACK_WEB.xml'][0] ?? null;
$assert(
    is_array($fallback)
    && $fallback['metric_key'] === 'total_revenue'
    && $fallback['source_fact_name'] === 'RevenueFromOperations'
    && $fallback['review_status'] === 'rejected'
    && $fallback['reviewed_at'] !== null,
    'Revenue-only evidence is retained in the audit trail as rejected.',
);

$duplicateRows = $byFile['INTEGRATED_FILING_INDAS_MIGRATE_DUPLICATE_WEB.xml'] ?? [];
$duplicateLegacy = array_values(array_filter(
    $duplicateRows,
    static fn (array $row): bool => $row['metric_key'] === 'total_revenue',
))[0] ?? null;
$duplicateCurrent = array_values(array_filter(
    $duplicateRows,
    static fn (array $row): bool => $row['metric_key'] === 'total_income',
))[0] ?? null;
$assert(
    is_array($duplicateLegacy) && $duplicateLegacy['review_status'] === 'rejected',
    'A legacy direct candidate is rejected when an equivalent total_income candidate already exists.',
);
$assert(
    is_array($duplicateCurrent) && $duplicateCurrent['review_status'] === 'pending',
    'The existing exact total_income candidate remains pending and unchanged.',
);

$second = $migrator->migrate();
$assert(
    $second['migrated'] === 0
    && $second['duplicate_retired'] === 0
    && $second['fallback_rejected'] === 0
    && $second['remaining_pending'] === 0,
    'Running the migration again is idempotent.',
);

echo "\nMigration verification completed with {$failed} failure(s).\n";
exit($failed === 0 ? 0 : 1);
