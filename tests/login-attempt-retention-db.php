#!/usr/bin/env php
<?php

declare(strict_types=1);

use HalalPulse\Auth\LoginAttemptMaintenance;
use HalalPulse\Database;

$config = require dirname(__DIR__) . '/app/bootstrap.php';
$pdo = Database::connect($config);
$maintenance = new LoginAttemptMaintenance($pdo);
$identityHash = hash('sha256', 'halalpulse-login-retention-test');
$ipHash = hash('sha256', '192.0.2.44');
$results = [];
$record = static function (bool $condition, string $message) use (&$results): void {
    $results[] = [$condition, $message];
};

$pdo->beginTransaction();
try {
    $statement = $pdo->prepare(
        <<<'SQL'
        INSERT INTO login_attempts (identity_hash, ip_hash, user_id, was_successful, attempted_at)
        VALUES (:identity_hash, :ip_hash, NULL, 0, :attempted_at)
        SQL
    );
    foreach ([
        '2000-01-01 00:00:00',
        '2000-01-02 00:00:00',
        '2000-01-03 00:00:00',
        '2002-01-01 00:00:00',
        '2002-01-02 00:00:00',
    ] as $attemptedAt) {
        $statement->execute([
            'identity_hash' => $identityHash,
            'ip_hash' => $ipHash,
            'attempted_at' => $attemptedAt,
        ]);
    }

    $deletedFirst = $maintenance->pruneBefore(new DateTimeImmutable('2001-01-01 00:00:00'), 2);
    $record($deletedFirst === 2, 'The first maintenance pass respects its strict row limit.');

    $oldCount = $pdo->prepare(
        'SELECT COUNT(*) FROM login_attempts WHERE identity_hash = :identity_hash AND attempted_at < :cutoff'
    );
    $oldCount->execute(['identity_hash' => $identityHash, 'cutoff' => '2001-01-01 00:00:00']);
    $record((int) $oldCount->fetchColumn() === 1, 'Rows beyond the bounded first pass remain available for the next run.');

    $deletedSecond = $maintenance->pruneBefore(new DateTimeImmutable('2001-01-01 00:00:00'), 100);
    $record($deletedSecond === 1, 'A later pass removes the remaining expired synthetic row.');

    $recentCount = $pdo->prepare(
        'SELECT COUNT(*) FROM login_attempts WHERE identity_hash = :identity_hash AND attempted_at >= :cutoff'
    );
    $recentCount->execute(['identity_hash' => $identityHash, 'cutoff' => '2001-01-01 00:00:00']);
    $record((int) $recentCount->fetchColumn() === 2, 'Attempts at or after the cutoff are preserved.');

    foreach ([0, 10001] as $invalidLimit) {
        $thrown = false;
        try {
            $maintenance->pruneBefore(new DateTimeImmutable('2001-01-01 00:00:00'), $invalidLimit);
        } catch (RuntimeException) {
            $thrown = true;
        }
        $record($thrown, "An invalid prune limit of {$invalidLimit} fails closed.");
    }
} finally {
    $pdo->rollBack();
}

$passed = 0;
$failed = 0;
foreach ($results as [$condition, $message]) {
    if ($condition) {
        $passed++;
        echo "[PASS] {$message}\n";
    } else {
        $failed++;
        echo "[FAIL] {$message}\n";
    }
}

echo "\n{$passed} passed, {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
