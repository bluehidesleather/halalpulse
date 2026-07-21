#!/usr/bin/env php
<?php

declare(strict_types=1);

use HalalPulse\Alerts\AlertConfiguration;
use HalalPulse\Database;

$config = require dirname(__DIR__) . '/app/bootstrap.php';
$documentPath = (string) $config->get('documents.storage_path', '');
$documentRealPath = $documentPath !== '' ? realpath($documentPath) : false;
$xbrlPath = (string) $config->get('sources.nse_integrated_rss.storage_path', '');
$xbrlRealPath = $xbrlPath !== '' ? realpath($xbrlPath) : false;
$webRealPath = realpath(HALALPULSE_ROOT . '/public_html');
$alertConfig = AlertConfiguration::fromConfig($config);
$alertConfigurationReady = !$alertConfig->enabled;
if ($alertConfig->enabled) {
    try {
        $alertConfig->assertReady();
        $alertConfigurationReady = true;
    } catch (Throwable) {
        $alertConfigurationReady = false;
    }
}

$backupEnabled = $config->get('backups.enabled', false) === true;
$backupPath = (string) $config->get('backups.storage_path', HALALPULSE_ROOT . '/storage/backups');
$backupNormalized = str_replace('\\', '/', $backupPath);
$webNormalized = is_string($webRealPath) ? str_replace('\\', '/', $webRealPath) : '';
$backupOutsideWeb = $backupNormalized !== ''
    && $webNormalized !== ''
    && $backupNormalized !== $webNormalized
    && !str_starts_with(rtrim($backupNormalized, '/') . '/', rtrim($webNormalized, '/') . '/');
$backupParent = dirname($backupPath);
$backupStorageWritable = is_dir($backupPath) ? is_writable($backupPath) : is_dir($backupParent) && is_writable($backupParent);
$backupConfigurationReady = !$backupEnabled || (
    strlen((string) $config->get('backups.encryption_passphrase', '')) >= 20
    && $backupOutsideWeb
    && $backupStorageWritable
    && is_file((string) $config->get('backups.mysqldump_binary', '/usr/bin/mysqldump'))
    && is_executable((string) $config->get('backups.mysqldump_binary', '/usr/bin/mysqldump'))
    && is_file((string) $config->get('backups.tar_binary', '/usr/bin/tar'))
    && is_executable((string) $config->get('backups.tar_binary', '/usr/bin/tar'))
    && in_array('aes-256-gcm', array_map('strtolower', openssl_get_cipher_methods()), true)
);
$loginWindowSeconds = max(1, (int) $config->get('security.login_window_seconds', 900));
$loginRetentionSeconds = (int) $config->get('security.login_attempt_retention_seconds', 604800);
$loginPruneMaximumRows = (int) $config->get('security.login_attempt_prune_max_rows', 5000);

$checks = [
    'php_version' => version_compare(PHP_VERSION, '8.3.0', '>='),
    'pdo_mysql' => extension_loaded('pdo_mysql'),
    'curl' => extension_loaded('curl'),
    'json' => extension_loaded('json'),
    'mbstring' => extension_loaded('mbstring'),
    'bcmath' => extension_loaded('bcmath'),
    'dom' => extension_loaded('dom'),
    'openssl' => extension_loaded('openssl'),
    'local_config' => is_file(HALALPULSE_ROOT . '/config/config.local.php'),
    'security_app_key' => strlen((string) $config->get('security.app_key', '')) >= 32,
    'secure_session_cookie' => $config->get('app.force_https', true) === true,
    'session_rotation_interval' => (int) $config->get('security.session_rotation_seconds', 900) >= 300,
    'login_attempt_retention' => $loginRetentionSeconds >= max(3600, $loginWindowSeconds * 2)
        && $loginRetentionSeconds <= 31536000,
    'login_attempt_prune_limit' => $loginPruneMaximumRows >= 1 && $loginPruneMaximumRows <= 10000,
    'private_document_storage' => is_string($documentRealPath)
        && is_writable($documentRealPath)
        && is_string($webRealPath)
        && $documentRealPath !== $webRealPath
        && !str_starts_with($documentRealPath, $webRealPath . DIRECTORY_SEPARATOR),
    'private_xbrl_storage' => is_string($xbrlRealPath)
        && is_writable($xbrlRealPath)
        && is_string($webRealPath)
        && $xbrlRealPath !== $webRealPath
        && !str_starts_with($xbrlRealPath, $webRealPath . DIRECTORY_SEPARATOR),
    'nse_integrated_five_minute_sync' => (int) $config->get('sources.nse_integrated_rss.interval_seconds') === 300,
    'nse_integrated_official_host' => parse_url(
        (string) $config->get('sources.nse_integrated_rss.endpoint', ''),
        PHP_URL_HOST,
    ) === 'nsearchives.nseindia.com',
    'hourly_nse_polling' => (int) $config->get('polling.nse_interval_seconds') === 3600,
    'hourly_bse_polling' => (int) $config->get('polling.bse_interval_seconds') === 3600,
    'hourly_government_polling' => (int) $config->get('government_polling.interval_seconds') === 3600,
    'alert_configuration' => $alertConfigurationReady,
    'backup_configuration' => $backupConfigurationReady,
];

$databaseError = null;

try {
    $pdo = Database::connect($config);
    $pdo->query('SELECT 1')->fetchColumn();
    $checks['database'] = true;
    $checks['database_timezone'] = (string) $pdo->query('SELECT @@session.time_zone')->fetchColumn()
        === (string) $config->get('database.session_timezone', '+05:30');
    $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $checks['users_table'] = true;
    $pdo->query('SELECT auth_version FROM users LIMIT 1')->fetch();
    $checks['users_auth_version_column'] = true;
    $pdo->query('SELECT COUNT(*) FROM login_attempts')->fetchColumn();
    $checks['login_attempts_table'] = true;
    $pdo->query('SELECT COUNT(*) FROM filing_documents')->fetchColumn();
    $checks['filing_documents_table'] = true;
    $pdo->query('SELECT COUNT(*) FROM document_metric_candidates')->fetchColumn();
    $checks['metric_candidates_table'] = true;
    $pdo->query('SELECT COUNT(*) FROM sharia_policies')->fetchColumn();
    $checks['sharia_policies_table'] = true;
    $pdo->query('SELECT COUNT(*) FROM company_sharia_activity_reviews')->fetchColumn();
    $checks['sharia_activity_reviews_table'] = true;
    $pdo->query('SELECT COUNT(*) FROM sharia_financial_inputs')->fetchColumn();
    $checks['sharia_financial_inputs_table'] = true;
    $pdo->query('SELECT source_integrated_item_id, source_fact_name FROM sharia_financial_inputs LIMIT 1')->fetch();
    $checks['sharia_xbrl_input_columns'] = true;
    $pdo->query('SELECT COUNT(*) FROM sharia_input_candidates')->fetchColumn();
    $checks['sharia_input_candidates_table'] = true;
    $pdo->query('SELECT COUNT(*) FROM sharia_screenings')->fetchColumn();
    $checks['sharia_screenings_table'] = true;
    $pdo->query('SELECT COUNT(*) FROM multibagger_methodologies')->fetchColumn();
    $checks['multibagger_methodologies_table'] = true;
    $pdo->query('SELECT COUNT(*) FROM multibagger_factor_reviews')->fetchColumn();
    $checks['multibagger_factor_reviews_table'] = true;
    $pdo->query('SELECT COUNT(*) FROM multibagger_valuation_reviews')->fetchColumn();
    $checks['multibagger_valuation_reviews_table'] = true;
    $pdo->query('SELECT COUNT(*) FROM multibagger_risk_reviews')->fetchColumn();
    $checks['multibagger_risk_reviews_table'] = true;
    $pdo->query('SELECT COUNT(*) FROM multibagger_scores')->fetchColumn();
    $checks['multibagger_scores_table'] = true;
    $pdo->query('SELECT COUNT(*) FROM government_announcements')->fetchColumn();
    $checks['government_announcements_table'] = true;
    $pdo->query('SELECT COUNT(*) FROM government_tailwind_reviews')->fetchColumn();
    $checks['government_tailwind_reviews_table'] = true;
    $pdo->query('SELECT COUNT(*) FROM government_source_checkpoints')->fetchColumn();
    $checks['government_source_checkpoints_table'] = true;
    $pdo->query('SELECT COUNT(*) FROM government_poll_runs')->fetchColumn();
    $checks['government_poll_runs_table'] = true;
    $pdo->query('SELECT COUNT(*) FROM alert_deliveries')->fetchColumn();
    $checks['alert_deliveries_table'] = true;
    $pdo->query('SELECT COUNT(*) FROM alert_recipients')->fetchColumn();
    $checks['alert_recipients_table'] = true;
    $pdo->query('SELECT COUNT(*) FROM alert_delivery_attempts')->fetchColumn();
    $checks['alert_delivery_attempts_table'] = true;
    $pdo->query('SELECT COUNT(*) FROM nse_sync_requests')->fetchColumn();
    $checks['nse_sync_requests_table'] = true;
    $pdo->query('SELECT COUNT(*) FROM nse_integrated_sync_runs')->fetchColumn();
    $checks['nse_integrated_sync_runs_table'] = true;
    $pdo->query('SELECT COUNT(*) FROM nse_integrated_feed_items')->fetchColumn();
    $checks['nse_integrated_feed_items_table'] = true;
    $pdo->query('SELECT COUNT(*) FROM financial_results')->fetchColumn();
    $checks['financial_results_table'] = true;
    $pdo->query('SELECT COUNT(*) FROM xbrl_facts')->fetchColumn();
    $checks['xbrl_facts_table'] = true;
} catch (Throwable $exception) {
    $checks['database'] = false;
    $checks['database_timezone'] ??= false;
    $checks['users_table'] ??= false;
    $checks['users_auth_version_column'] ??= false;
    $checks['login_attempts_table'] ??= false;
    $checks['filing_documents_table'] ??= false;
    $checks['metric_candidates_table'] ??= false;
    $checks['sharia_policies_table'] ??= false;
    $checks['sharia_activity_reviews_table'] ??= false;
    $checks['sharia_financial_inputs_table'] ??= false;
    $checks['sharia_xbrl_input_columns'] ??= false;
    $checks['sharia_input_candidates_table'] ??= false;
    $checks['sharia_screenings_table'] ??= false;
    $checks['multibagger_methodologies_table'] ??= false;
    $checks['multibagger_factor_reviews_table'] ??= false;
    $checks['multibagger_valuation_reviews_table'] ??= false;
    $checks['multibagger_risk_reviews_table'] ??= false;
    $checks['multibagger_scores_table'] ??= false;
    $checks['government_announcements_table'] ??= false;
    $checks['government_tailwind_reviews_table'] ??= false;
    $checks['government_source_checkpoints_table'] ??= false;
    $checks['government_poll_runs_table'] ??= false;
    $checks['alert_deliveries_table'] ??= false;
    $checks['alert_recipients_table'] ??= false;
    $checks['alert_delivery_attempts_table'] ??= false;
    $checks['nse_sync_requests_table'] ??= false;
    $checks['nse_integrated_sync_runs_table'] ??= false;
    $checks['nse_integrated_feed_items_table'] ??= false;
    $checks['financial_results_table'] ??= false;
    $checks['xbrl_facts_table'] ??= false;
    $databaseError = $exception->getMessage();
}

foreach ($checks as $name => $passed) {
    printf("[%s] %s\n", $passed ? 'PASS' : 'FAIL', $name);
}

if ($databaseError !== null) {
    fwrite(STDERR, "Database: {$databaseError}\n");
}

exit(in_array(false, $checks, true) ? 1 : 0);
