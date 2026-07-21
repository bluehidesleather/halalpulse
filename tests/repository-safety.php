#!/usr/bin/env php
<?php

declare(strict_types=1);

use HalalPulse\Operations\RepositorySafetyAuditor;

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

$requiredIgnore = implode("\n", [
    '/config/config.local.php',
    '/config/sharia-policy.local.json',
    '/config/multibagger-methodology.local.json',
    '/storage/documents/*',
    '/storage/xbrl/*',
    '/storage/backups/*',
]) . "\n";
$baseEntries = [
    ['path' => '.gitignore', 'mode' => '100644'],
    ['path' => 'bin/mysqldump-no-tablespaces', 'mode' => '100755'],
    ['path' => 'app/Safe.php', 'mode' => '100644'],
];
$baseFiles = [
    '.gitignore' => $requiredIgnore,
    'bin/mysqldump-no-tablespaces' => "#!/bin/sh\nexit 0\n",
    'app/Safe.php' => "<?php\nreturn true;\n",
];
$read = static fn (array $files): callable => static fn (string $path): ?string => $files[$path] ?? null;
$auditor = new RepositorySafetyAuditor();

$clean = $auditor->audit($baseEntries, $read($baseFiles));
$assert($clean['passed'] === true, 'A clean tracked-file inventory passes the repository safety audit.');
$assert($clean['files_scanned'] === 3, 'The audit reports the number of scanned text files.');

$localConfigEntries = [...$baseEntries, ['path' => 'config/config.local.php', 'mode' => '100644']];
$localConfigFiles = $baseFiles + ['config/config.local.php' => "<?php\nreturn [];\n"];
$localConfig = $auditor->audit($localConfigEntries, $read($localConfigFiles));
$assert($localConfig['passed'] === false, 'A tracked private local configuration is rejected.');

$privateKeyFiles = $baseFiles;
$privateKeyFiles['app/Safe.php'] = '-----BEGIN ' . 'PRIVATE KEY-----' . "\nsynthetic\n";
$privateKey = $auditor->audit($baseEntries, $read($privateKeyFiles));
$assert($privateKey['passed'] === false, 'Private-key material is detected in tracked text.');

$tokenFiles = $baseFiles;
$tokenFiles['app/Safe.php'] = 'gh' . 'p_' . str_repeat('A', 24);
$token = $auditor->audit($baseEntries, $read($tokenFiles));
$assert($token['passed'] === false, 'High-confidence GitHub access-token shapes are detected.');

$temporaryDomainFiles = $baseFiles;
$temporaryDomainFiles['app/Safe.php'] = 'https://example.' . 'hostingersite.com';
$temporaryDomain = $auditor->audit($baseEntries, $read($temporaryDomainFiles));
$assert($temporaryDomain['passed'] === false, 'Temporary Hostinger domains are rejected outside the explicit test fixture allowlist.');

$allowlistedEntries = [
    ['path' => '.gitignore', 'mode' => '100644'],
    ['path' => 'bin/mysqldump-no-tablespaces', 'mode' => '100755'],
    ['path' => 'tests/operations-readiness.php', 'mode' => '100644'],
];
$allowlistedFiles = [
    '.gitignore' => $requiredIgnore,
    'bin/mysqldump-no-tablespaces' => "#!/bin/sh\nexit 0\n",
    'tests/operations-readiness.php' => 'https://example.' . 'hostingersite.com',
];
$allowlisted = $auditor->audit($allowlistedEntries, $read($allowlistedFiles));
$assert($allowlisted['passed'] === true, 'The intentional temporary-domain readiness fixture is narrowly allowlisted.');

$wrongModeEntries = $baseEntries;
$wrongModeEntries[1]['mode'] = '100644';
$wrongMode = $auditor->audit($wrongModeEntries, $read($baseFiles));
$assert($wrongMode['passed'] === false, 'The least-privilege backup wrapper must remain executable in Git.');

$missingIgnoreFiles = $baseFiles;
$missingIgnoreFiles['.gitignore'] = "/config/config.local.php\n";
$missingIgnore = $auditor->audit($baseEntries, $read($missingIgnoreFiles));
$assert($missingIgnore['passed'] === false, 'Required private runtime ignore rules cannot be removed silently.');

echo "\n{$passed} passed, {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
