#!/usr/bin/env php
<?php

declare(strict_types=1);

use HalalPulse\Operations\BackupEncryptor;
use HalalPulse\Operations\BackupService;

$config = require dirname(__DIR__) . '/app/bootstrap.php';

try {
    $service = new BackupService($config, new BackupEncryptor(), HALALPULSE_ROOT);
    $status = $service->latestStatus();
    if ($status === null) {
        throw new RuntimeException('No successful backup status is available.');
    }

    $path = (string) ($status['path'] ?? '');
    $createdAt = (string) ($status['created_at'] ?? '');
    $encryptedSha256 = (string) ($status['encrypted_sha256'] ?? '');
    $plaintextSha256 = (string) ($status['plaintext_sha256'] ?? '');
    if ($path === '' || !is_file($path) || !is_readable($path)) {
        throw new RuntimeException('The latest encrypted backup file is missing or unreadable.');
    }
    if (strlen($encryptedSha256) !== 64 || !hash_equals($encryptedSha256, (string) hash_file('sha256', $path))) {
        throw new RuntimeException('The latest encrypted backup SHA-256 does not match its status record.');
    }

    $created = new DateTimeImmutable($createdAt);
    $maximumHours = max(1, min(720, (int) $config->get('backups.maximum_age_hours', 30)));
    $ageSeconds = time() - $created->getTimestamp();
    if ($ageSeconds < 0 || $ageSeconds > $maximumHours * 3600) {
        throw new RuntimeException("The latest backup is older than {$maximumHours} hours.");
    }

    $verifyEncryptedContents = in_array('--decrypt', $argv, true);
    if ($verifyEncryptedContents) {
        $passphrase = $config->requireString('backups.encryption_passphrase');
        if (!(new BackupEncryptor())->verify($path, $passphrase, $plaintextSha256)) {
            throw new RuntimeException('Authenticated decryption verification failed.');
        }
    }

    fwrite(STDOUT, json_encode([
        'status' => 'succeeded',
        'filename' => basename($path),
        'created_at' => $createdAt,
        'age_hours' => round($ageSeconds / 3600, 2),
        'encrypted_sha256' => $encryptedSha256,
        'authenticated_decryption_checked' => $verifyEncryptedContents,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
} catch (Throwable $exception) {
    fwrite(STDERR, json_encode([
        'status' => 'failed',
        'error' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
    exit(1);
}
