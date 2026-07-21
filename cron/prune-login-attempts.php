#!/usr/bin/env php
<?php

declare(strict_types=1);

use HalalPulse\Auth\LoginAttemptMaintenance;
use HalalPulse\Database;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$config = require dirname(__DIR__) . '/app/bootstrap.php';
$windowSeconds = max(1, (int) $config->get('security.login_window_seconds', 900));
$retentionSeconds = (int) $config->get('security.login_attempt_retention_seconds', 604800);
$minimumRetention = max(3600, $windowSeconds * 2);
if ($retentionSeconds < $minimumRetention || $retentionSeconds > 31536000) {
    fwrite(STDERR, "security.login_attempt_retention_seconds must be between {$minimumRetention} and 31536000.\n");
    exit(2);
}

$maximumRows = (int) $config->get('security.login_attempt_prune_max_rows', 5000);
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--limit=(\d+)$/D', (string) $argument, $matches) === 1) {
        $maximumRows = (int) $matches[1];
        continue;
    }
    fwrite(STDERR, "Usage: php cron/prune-login-attempts.php [--limit=1..10000]\n");
    exit(2);
}
if ($maximumRows < 1 || $maximumRows > 10000) {
    fwrite(STDERR, "Login-attempt prune limit must be between 1 and 10000 rows.\n");
    exit(2);
}

$cutoff = (new DateTimeImmutable())->modify('-' . $retentionSeconds . ' seconds');
$deleted = (new LoginAttemptMaintenance(Database::connect($config)))->pruneBefore($cutoff, $maximumRows);

echo json_encode([
    'status' => 'succeeded',
    'cutoff' => $cutoff->format(DATE_ATOM),
    'retention_seconds' => $retentionSeconds,
    'maximum_rows' => $maximumRows,
    'deleted' => $deleted,
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
