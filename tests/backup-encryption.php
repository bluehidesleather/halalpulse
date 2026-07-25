#!/usr/bin/env php
<?php

declare(strict_types=1);

use HalalPulse\Operations\BackupEncryptor;

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

$directory = sys_get_temp_dir() . '/halalpulse-backup-test-' . bin2hex(random_bytes(6));
if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
    throw new RuntimeException('Unable to create backup test directory.');
}
$source = $directory . '/source.bin';
$encrypted = $directory . '/source.hpbak';
$decrypted = $directory . '/decrypted.bin';
$wrongDestination = $directory . '/wrong.bin';
$tampered = $directory . '/tampered.hpbak';
$tamperedDestination = $directory . '/tampered.bin';
$passphrase = 'synthetic-test-passphrase-with-adequate-length';
$encryptor = new BackupEncryptor();

try {
    $payload = random_bytes(1048576 + 137) . "HalalPulse deterministic backup test\n";
    file_put_contents($source, $payload, LOCK_EX);
    $plainHash = hash('sha256', $payload);

    $encryptedResult = $encryptor->encrypt($source, $encrypted, $passphrase);
    $assert(is_file($encrypted) && $encryptedResult['chunks'] === 2, 'A multi-chunk backup is encrypted using the authenticated envelope.');
    $assert(strlen($encryptedResult['sha256']) === 64 && hash_equals($encryptedResult['sha256'], (string) hash_file('sha256', $encrypted)), 'Encrypted backup SHA-256 matches the published envelope.');

    $decryptedResult = $encryptor->decrypt($encrypted, $decrypted, $passphrase);
    $assert(hash_equals($plainHash, $decryptedResult['sha256']), 'Authenticated decryption reproduces the original plaintext SHA-256.');
    $assert(hash_equals($payload, (string) file_get_contents($decrypted)), 'Authenticated decryption reproduces the exact original bytes.');
    $assert($encryptor->verify($encrypted, $passphrase, $plainHash), 'Backup verification succeeds with the correct passphrase and plaintext hash.');

    $wrongPasswordRejected = false;
    try {
        $encryptor->decrypt($encrypted, $wrongDestination, 'wrong-passphrase-that-is-still-long-enough');
    } catch (RuntimeException) {
        $wrongPasswordRejected = true;
    }
    $assert($wrongPasswordRejected && !file_exists($wrongDestination), 'A wrong passphrase fails authentication without leaving plaintext behind.');

    copy($encrypted, $tampered);
    $handle = fopen($tampered, 'r+b');
    if ($handle === false) {
        throw new RuntimeException('Unable to open tampered test file.');
    }
    fseek($handle, 80);
    $byte = fread($handle, 1);
    fseek($handle, 80);
    fwrite($handle, chr((ord($byte === '' ? "\0" : $byte) ^ 0x01)));
    fclose($handle);

    $tamperRejected = false;
    try {
        $encryptor->decrypt($tampered, $tamperedDestination, $passphrase);
    } catch (RuntimeException) {
        $tamperRejected = true;
    }
    $assert($tamperRejected && !file_exists($tamperedDestination), 'Ciphertext tampering is detected and no plaintext is retained.');

    $shortPassphraseRejected = false;
    try {
        $encryptor->encrypt($source, $directory . '/short.hpbak', 'too-short');
    } catch (RuntimeException) {
        $shortPassphraseRejected = true;
    }
    $assert($shortPassphraseRejected, 'Short backup passphrases are rejected.');

    $wrapper = dirname(__DIR__) . '/bin/mysqldump-no-tablespaces';
    $capturedArguments = $directory . '/mysqldump-arguments.txt';
    $fakeMysqldump = $directory . '/fake-mysqldump';
    $fakeScript = "#!/bin/sh\nprintf '%s\\n' \"\$@\" > " . escapeshellarg($capturedArguments) . "\n";
    file_put_contents($fakeMysqldump, $fakeScript, LOCK_EX);
    chmod($fakeMysqldump, 0700);

    $runWrapper = static function (array $arguments) use ($wrapper, $fakeMysqldump): int {
        $process = proc_open(
            array_merge([$wrapper], $arguments),
            [0 => ['file', '/dev/null', 'rb'], 1 => ['file', '/dev/null', 'wb'], 2 => ['file', '/dev/null', 'wb']],
            $pipes,
            dirname(__DIR__),
            ['HALALPULSE_MYSQLDUMP_BIN' => $fakeMysqldump, 'PATH' => '/usr/local/bin:/usr/bin:/bin'],
        );
        if (!is_resource($process)) {
            return 127;
        }

        return proc_close($process);
    };

    $localhostExit = $runWrapper(['--host=localhost', '--port=3306', '--user=tester', 'halalpulse']);
    $localhostArguments = file($capturedArguments, FILE_IGNORE_NEW_LINES) ?: [];
    $assert($localhostExit === 0 && in_array('--no-tablespaces', $localhostArguments, true), 'The wrapper always disables tablespace metadata for least-privilege backups.');
    $assert(in_array('--protocol=SOCKET', $localhostArguments, true), 'A localhost backup is forced through the local MySQL socket instead of IPv6 TCP.');

    @unlink($capturedArguments);
    $tcpExit = $runWrapper(['--host=127.0.0.1', '--port=3306', '--user=tester', 'halalpulse']);
    $tcpArguments = file($capturedArguments, FILE_IGNORE_NEW_LINES) ?: [];
    $assert($tcpExit === 0 && !in_array('--protocol=SOCKET', $tcpArguments, true), 'An explicit TCP database host remains on TCP for CI and remote-compatible deployments.');
} finally {
    foreach (glob($directory . '/*') ?: [] as $path) {
        @unlink($path);
    }
    @rmdir($directory);
}

echo "\n{$passed} passed, {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
