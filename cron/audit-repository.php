#!/usr/bin/env php
<?php

declare(strict_types=1);

use HalalPulse\Operations\RepositorySafetyAuditor;

require dirname(__DIR__) . '/app/bootstrap.php';

$root = dirname(__DIR__);
$jsonOutput = in_array('--json', $argv, true);
$gitBinary = is_executable('/usr/bin/git') ? '/usr/bin/git' : 'git';
$process = proc_open(
    [$gitBinary, '-C', $root, 'ls-files', '--stage', '-z'],
    [
        0 => ['file', '/dev/null', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ],
    $pipes,
    $root,
    ['PATH' => '/usr/local/bin:/usr/bin:/bin'],
);
if (!is_resource($process)) {
    fwrite(STDERR, "Unable to start Git repository audit.\n");
    exit(2);
}

$output = stream_get_contents($pipes[1]);
$error = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exitCode = proc_close($process);
if ($exitCode !== 0 || !is_string($output)) {
    fwrite(STDERR, 'Unable to list tracked repository files' . ($error === '' ? ".\n" : ': ' . trim((string) $error) . "\n"));
    exit(2);
}

$entries = [];
foreach (explode("\0", rtrim($output, "\0")) as $record) {
    if ($record === '') {
        continue;
    }
    if (preg_match('/^(\d{6}) [0-9a-f]{40} \d\t(.+)$/D', $record, $matches) !== 1) {
        fwrite(STDERR, "Unable to parse a tracked Git entry.\n");
        exit(2);
    }
    $entries[] = ['mode' => $matches[1], 'path' => $matches[2]];
}

$report = (new RepositorySafetyAuditor())->audit(
    $entries,
    static function (string $path) use ($root): ?string {
        $absolute = $root . '/' . $path;
        if (!is_file($absolute) || !is_readable($absolute)) {
            return null;
        }
        $content = file_get_contents($absolute);

        return is_string($content) ? $content : null;
    },
);

if ($jsonOutput) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
    exit($report['passed'] ? 0 : 1);
}

fwrite(STDOUT, "HalalPulse public repository safety audit\n");
fwrite(STDOUT, 'Tracked text files scanned: ' . $report['files_scanned'] . "\n\n");
foreach ($report['warnings'] as $warning) {
    fwrite(STDOUT, "[WARN] {$warning}\n");
}
foreach ($report['failures'] as $failure) {
    fwrite(STDOUT, "[FAIL] {$failure}\n");
}
if ($report['passed']) {
    fwrite(STDOUT, "[PASS] No tracked private configuration, backup material, high-confidence credentials, production account paths, or temporary domains were detected.\n");
}

exit($report['passed'] ? 0 : 1);
