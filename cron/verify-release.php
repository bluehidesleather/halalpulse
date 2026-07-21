#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$php = PHP_BINARY;
$commands = [
    'core tests' => [$php, $root . '/tests/run.php'],
    'screening and ranking tests' => [$php, $root . '/tests/screening-ranking.php'],
    'Sharia policy readiness tests' => [$php, $root . '/tests/sharia-policy-readiness.php'],
    'Sharia evidence readiness tests' => [$php, $root . '/tests/sharia-evidence-readiness.php'],
    'multibagger methodology readiness tests' => [$php, $root . '/tests/multibagger-methodology-readiness.php'],
    'multibagger evidence readiness tests' => [$php, $root . '/tests/multibagger-evidence-readiness.php'],
    'backup encryption tests' => [$php, $root . '/tests/backup-encryption.php'],
    'operations readiness tests' => [$php, $root . '/tests/operations-readiness.php'],
    'repository safety tests' => [$php, $root . '/tests/repository-safety.php'],
    'session security tests' => [$php, $root . '/tests/session-security.php'],
    'HTTP request security tests' => [$php, $root . '/tests/http-request-security.php'],
    'official evidence URL security tests' => [$php, $root . '/tests/official-url-security.php'],
    'Telegram transport security tests' => [$php, $root . '/tests/telegram-security.php'],
    'light luxury design system tests' => [$php, $root . '/tests/design-system.php'],
    'account session revocation integration' => [$php, $root . '/tests/account-session-revocation-db.php'],
    'public repository safety audit' => [$php, $root . '/cron/audit-repository.php'],
    'deployment health check' => [$php, $root . '/cron/healthcheck.php'],
];

$commit = 'unknown';
$git = proc_open(
    ['/usr/bin/git', '-C', $root, 'rev-parse', 'HEAD'],
    [0 => ['file', '/dev/null', 'rb'], 1 => ['pipe', 'rb'], 2 => ['file', '/dev/null', 'wb']],
    $gitPipes,
);
if (is_resource($git)) {
    $output = stream_get_contents($gitPipes[1]);
    fclose($gitPipes[1]);
    if (proc_close($git) === 0 && is_string($output) && preg_match('/^[a-f0-9]{40}\s*$/D', $output) === 1) {
        $commit = trim($output);
    }
}

fwrite(STDOUT, "HalalPulse release verification\nCommit: {$commit}\nPHP: " . PHP_VERSION . "\n\n");
$started = microtime(true);
$completed = [];

foreach ($commands as $label => $command) {
    fwrite(STDOUT, "=== {$label} ===\n");
    $process = proc_open(
        $command,
        [0 => ['file', '/dev/null', 'rb'], 1 => STDOUT, 2 => STDERR],
        $pipes,
        $root,
    );
    if (!is_resource($process)) {
        fwrite(STDERR, "Unable to start {$label}.\n");
        exit(2);
    }
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        fwrite(STDERR, "\n[FAILED] {$label} exited with code {$exitCode}. Release verification stopped.\n");
        exit(1);
    }
    $completed[] = $label;
    fwrite(STDOUT, "[OK] {$label}\n\n");
}

$duration = round(microtime(true) - $started, 2);
fwrite(STDOUT, "Release verification succeeded.\n");
fwrite(STDOUT, 'Completed checks: ' . count($completed) . "\n");
fwrite(STDOUT, "Duration: {$duration} seconds\n");
fwrite(STDOUT, "Commit: {$commit}\n");
