#!/usr/bin/env php
<?php

declare(strict_types=1);

use HalalPulse\Database;
use HalalPulse\Operations\BackupEncryptor;
use HalalPulse\Operations\BackupService;
use HalalPulse\Operations\OperationsReadiness;
use HalalPulse\Operations\OperationsReadinessRepository;

$config = require dirname(__DIR__) . '/app/bootstrap.php';

try {
    $pdo = Database::connect($config);
    $snapshot = (new OperationsReadinessRepository($pdo))->snapshot();
    $backupStatus = (new BackupService($config, new BackupEncryptor(), HALALPULSE_ROOT))->latestStatus();
    $report = (new OperationsReadiness())->assess($config, $snapshot, $backupStatus);

    if (in_array('--json', $argv, true)) {
        fwrite(STDOUT, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
    } else {
        fwrite(STDOUT, "HalalPulse production readiness\n\n");
        foreach ($report['gates'] as $name => $passed) {
            printf("[%s] gate:%s\n", $passed ? 'PASS' : 'BLOCK', $name);
        }
        fwrite(STDOUT, "\nChecks\n");
        foreach ($report['checks'] as $check) {
            $label = match ($check['status']) {
                'passed' => 'PASS',
                'warning' => 'WARN',
                default => 'BLOCK',
            };
            printf("[%s] %s — %s\n", $label, $check['label'], $check['detail']);
        }
        if ($report['warnings'] !== []) {
            fwrite(STDOUT, "\nWarnings\n");
            foreach ($report['warnings'] as $warning) {
                fwrite(STDOUT, "- {$warning}\n");
            }
        }
        fwrite(STDOUT, "\nResult: " . ($report['fully_operational'] ? 'FULLY OPERATIONAL' : 'NOT FULLY OPERATIONAL') . "\n");
    }

    exit($report['fully_operational'] ? 0 : 1);
} catch (Throwable $exception) {
    fwrite(STDERR, "Readiness inspection failed: {$exception->getMessage()}\n");
    exit(2);
}
