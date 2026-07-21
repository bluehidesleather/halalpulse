#!/usr/bin/env php
<?php

declare(strict_types=1);

use HalalPulse\Config;
use HalalPulse\Operations\OperationsReadiness;

require dirname(__DIR__) . '/app/bootstrap.php';

$passed = 0;
$failed = 0;
$assert = static function (bool $condition, string $message) use (&$passed, &$failed): void {
    if ($condition) {
        $passed++;
        echo "[PASS] {$message}\n";
        return;
    }
    $failed++;
    echo "[FAIL] {$message}\n";
};

$now = new DateTimeImmutable('2026-07-21T18:00:00+05:30');
$backupPath = sys_get_temp_dir() . '/halalpulse-operations-' . bin2hex(random_bytes(6)) . '.hpbak';
file_put_contents($backupPath, 'synthetic encrypted backup bytes', LOCK_EX);
$backupHash = hash_file('sha256', $backupPath);

$values = [
    'app' => ['environment' => 'production', 'timezone' => 'Asia/Kolkata', 'force_https' => true],
    'security' => ['app_key' => str_repeat('a', 64)],
    'sources' => [
        'nse_integrated_rss' => ['enabled' => true],
        'nse' => ['enabled' => false],
        'bse' => ['enabled' => false],
    ],
    'government_sources' => [
        'pib' => ['enabled' => true],
        'sebi' => ['enabled' => false],
        'rbi' => ['enabled' => false],
        'mca' => ['enabled' => false],
        'budget' => ['enabled' => false],
    ],
    'alerts' => [
        'enabled' => true,
        'app_base_url' => 'https://halalpulse.example.org',
        'telegram' => ['bot_token' => 'synthetic-private-token-long-enough'],
    ],
    'backups' => [
        'enabled' => true,
        'maximum_age_hours' => 30,
        'encryption_passphrase' => 'synthetic-backup-passphrase-long-enough',
    ],
];
$snapshot = [
    'active_admins' => 1,
    'sharia_policy_version' => 'verified-policy-v1',
    'methodology_version' => 'reviewed-methodology-v1',
    'pending_sharia_candidates' => 3,
    'activity_reviewed_companies' => 1,
    'sharia_passes' => 1,
    'completed_scores' => 1,
    'active_alert_recipients' => 1,
    'unknown_alert_deliveries' => 0,
    'failed_alert_deliveries' => 0,
    'nse_integrated' => [
        'status' => 'succeeded',
        'started_at' => '2026-07-21 17:50:00',
        'finished_at' => '2026-07-21 17:50:10',
        'failed_items' => 0,
        'pending_items' => 0,
    ],
    'legacy_sources' => [],
    'government_sources' => [
        'pib' => [
            'last_run_status' => 'succeeded',
            'last_run_at' => '2026-07-21 17:10:00',
            'last_successful_poll_at' => '2026-07-21 17:10:00',
            'consecutive_failures' => 0,
        ],
    ],
];
$backupStatus = [
    'path' => $backupPath,
    'encrypted_sha256' => $backupHash,
    'created_at' => '2026-07-21T12:00:00+00:00',
];
$service = new OperationsReadiness();

try {
    $complete = $service->assess(new Config($values), $snapshot, $backupStatus, $now);
    $assert($complete['fully_operational'] === true, 'A complete synthetic production environment passes every operational gate.');
    $assert($complete['gates']['runtime'] && $complete['gates']['ingestion'], 'Runtime and ingestion gates are independently reported.');
    $assert($complete['warnings'] !== [], 'Disabled optional legacy coverage and pending review work remain visible as warnings.');

    $noPolicy = $snapshot;
    $noPolicy['sharia_policy_version'] = null;
    $policyReport = $service->assess(new Config($values), $noPolicy, $backupStatus, $now);
    $assert($policyReport['gates']['screening'] === false, 'Screening is blocked without an active verified policy.');
    $assert($policyReport['gates']['ranking'] === false, 'Ranking cannot bypass the Sharia policy gate.');

    $staleFeed = $snapshot;
    $staleFeed['nse_integrated']['finished_at'] = '2026-07-21 12:00:00';
    $assert($service->assess(new Config($values), $staleFeed, $backupStatus, $now)['gates']['ingestion'] === false, 'A stale five-minute worker blocks ingestion readiness.');

    $failedFeed = $snapshot;
    $failedFeed['nse_integrated']['failed_items'] = 2;
    $assert($service->assess(new Config($values), $failedFeed, $backupStatus, $now)['gates']['ingestion'] === false, 'Failed integrated filings block ingestion readiness.');

    $noGovernmentValues = $values;
    $noGovernmentValues['government_sources']['pib']['enabled'] = false;
    $assert($service->assess(new Config($noGovernmentValues), $snapshot, $backupStatus, $now)['gates']['ranking'] === false, 'Ranking remains blocked until at least one official government source is enabled.');

    $unknownDelivery = $snapshot;
    $unknownDelivery['unknown_alert_deliveries'] = 1;
    $unknownReport = $service->assess(new Config($values), $unknownDelivery, $backupStatus, $now);
    $assert($unknownReport['gates']['alerts'] === false, 'Unknown provider outcomes block automatic alerts.');
    $assert($unknownReport['blockers'] !== [], 'Unknown provider outcomes are surfaced as blockers.');

    $disabledAlerts = $values;
    $disabledAlerts['alerts']['enabled'] = false;
    $assert($service->assess(new Config($disabledAlerts), $snapshot, $backupStatus, $now)['gates']['alerts'] === false, 'Disabled alerts are reported without pretending delivery is ready.');

    $staleBackup = $backupStatus;
    $staleBackup['created_at'] = '2026-07-18T00:00:00+00:00';
    $assert($service->assess(new Config($values), $snapshot, $staleBackup, $now)['gates']['backups'] === false, 'A stale backup blocks continuity readiness.');

    $temporaryDomain = $values;
    $temporaryDomain['alerts']['app_base_url'] = 'https://example.hostingersite.com';
    $temporaryReport = $service->assess(new Config($temporaryDomain), $snapshot, $backupStatus, $now);
    $assert($temporaryReport['gates']['runtime'] === true, 'A valid temporary HTTPS domain remains technically runnable.');
    $assert(array_filter($temporaryReport['checks'], static fn (array $check): bool => $check['key'] === 'permanent_domain') !== [], 'A temporary Hostinger domain is explicitly warned about.');
} finally {
    @unlink($backupPath);
}

echo "\n{$passed} passed, {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
