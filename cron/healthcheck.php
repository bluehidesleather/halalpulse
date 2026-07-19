#!/usr/bin/env php
<?php

declare(strict_types=1);

use HalalPulse\Database;
use HalalPulse\Alerts\AlertConfiguration;

$config = require dirname(__DIR__) . '/app/bootstrap.php';
$documentPath = (string) $config->get('documents.storage_path', '');
$documentRealPath = $documentPath !== '' ? realpath($documentPath) : false;
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
    'private_document_storage' => is_string($documentRealPath)
        && is_writable($documentRealPath)
        && is_string($webRealPath)
        && $documentRealPath !== $webRealPath
        && !str_starts_with($documentRealPath, $webRealPath . DIRECTORY_SEPARATOR),
    'hourly_nse_polling' => (int) $config->get('polling.nse_interval_seconds') === 3600,
    'hourly_bse_polling' => (int) $config->get('polling.bse_interval_seconds') === 3600,
    'hourly_government_polling' => (int) $config->get('government_polling.interval_seconds') === 3600,
    'alert_configuration' => $alertConfigurationReady,
];

$databaseError = null;

try {
    $pdo = Database::connect($config);
    $pdo->query('SELECT 1')->fetchColumn();
    $checks['database'] = true;
    $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $checks['users_table'] = true;
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
} catch (Throwable $exception) {
    $checks['database'] = false;
    $checks['users_table'] ??= false;
    $checks['login_attempts_table'] ??= false;
    $checks['filing_documents_table'] ??= false;
    $checks['metric_candidates_table'] ??= false;
    $checks['sharia_policies_table'] ??= false;
    $checks['sharia_activity_reviews_table'] ??= false;
    $checks['sharia_financial_inputs_table'] ??= false;
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
    $databaseError = $exception->getMessage();
}

foreach ($checks as $name => $passed) {
    printf("[%s] %s\n", $passed ? 'PASS' : 'FAIL', $name);
}

if ($databaseError !== null) {
    fwrite(STDERR, "Database: {$databaseError}\n");
}

exit(in_array(false, $checks, true) ? 1 : 0);
