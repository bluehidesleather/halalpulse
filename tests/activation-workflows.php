#!/usr/bin/env php
<?php

declare(strict_types=1);

use HalalPulse\Alerts\AlertActivationOrigin;
use HalalPulse\Support\PrivateConfigEditor;
use HalalPulse\Support\PrivateTemplatePreparer;

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

$root = sys_get_temp_dir() . '/halalpulse-activation-' . bin2hex(random_bytes(8));
$configDirectory = $root . '/config';
mkdir($configDirectory, 0700, true);
$configPath = $configDirectory . '/config.local.php';
$templatePath = $configDirectory . '/template.json';
$workingPath = $configDirectory . '/working.json';
$initial = [
    'app' => ['environment' => 'testing'],
    'database' => ['user' => 'synthetic-user', 'password' => 'synthetic-private-value'],
    'backups' => ['enabled' => false, 'retention_days' => 7, 'include_paths' => ['old-path', 'unexpected-path']],
    'alerts' => ['enabled' => false, 'telegram' => ['bot_token' => '']],
];
file_put_contents($configPath, "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($initial, true) . ";\n", LOCK_EX);
chmod($configPath, 0600);
file_put_contents($templatePath, "{\n  \"status\": \"template\"\n}\n", LOCK_EX);

try {
    (new PrivateConfigEditor($root))->apply([
        'backups' => [
            'enabled' => true,
            'retention_days' => 14,
            'encryption_passphrase' => 'synthetic-backup-value-long-enough',
            'include_paths' => ['config/config.local.php'],
        ],
    ]);
    $updated = require $configPath;
    $assert(is_array($updated) && $updated['backups']['enabled'] === true, 'Private configuration editor applies a nested activation patch.');
    $assert(($updated['database']['password'] ?? null) === 'synthetic-private-value', 'Private configuration editor preserves unrelated secret values.');
    $assert(($updated['backups']['retention_days'] ?? null) === 14, 'Private configuration editor replaces the requested nested value.');
    $assert(($updated['backups']['include_paths'] ?? null) === ['config/config.local.php'], 'List-valued configuration is replaced instead of retaining unexpected paths.');
    $assert((fileperms($configPath) & 0777) === 0600, 'Updated private configuration remains mode 0600.');
    $assert(glob($configDirectory . '/.config.local.*.tmp') === [], 'Atomic update leaves no secret-bearing temporary file behind.');

    $preparer = new PrivateTemplatePreparer();
    $created = $preparer->prepare($templatePath, $workingPath);
    $assert($created && file_get_contents($workingPath) === file_get_contents($templatePath), 'Private template preparer copies the complete safe template.');
    $assert((fileperms($workingPath) & 0777) === 0600, 'Prepared research working file is protected with mode 0600.');
    file_put_contents($workingPath, "{\"reviewed\":false}\n", LOCK_EX);
    $assert($preparer->prepare($templatePath, $workingPath) === false, 'Private template preparer never overwrites an existing working file.');
    $assert(file_get_contents($workingPath) === "{\"reviewed\":false}\n", 'Existing research work remains unchanged.');

    $target = $configDirectory . '/target.php';
    file_put_contents($target, "<?php return [];\n", LOCK_EX);
    unlink($configPath);
    symlink($target, $configPath);
    $symlinkRejected = false;
    try {
        (new PrivateConfigEditor($root))->apply(['backups' => ['enabled' => false]]);
    } catch (RuntimeException) {
        $symlinkRejected = true;
    }
    $assert($symlinkRejected, 'Private configuration editor refuses a symbolic-link target.');

    $permanentAccepted = true;
    try {
        AlertActivationOrigin::assertPermanent('https://research.example.org');
    } catch (InvalidArgumentException) {
        $permanentAccepted = false;
    }
    $assert($permanentAccepted, 'A permanent DNS-style HTTPS origin is accepted for Telegram preparation.');

    $temporaryHost = 'temporary.' . 'hostingersite' . '.com';
    $unsafeOrigins = [
        'https://halalpulse.example',
        'https://' . $temporaryHost,
        'http://research.example.org',
        'https://127.0.0.1',
        'https://research.example.org:8443',
        'https://research.example.org/private',
        'https://user@research.example.org',
        'https://research.example.org?view=summary',
        'https://research.example.org#fragment',
    ];
    foreach ($unsafeOrigins as $unsafeOrigin) {
        $rejected = false;
        try {
            AlertActivationOrigin::assertPermanent($unsafeOrigin);
        } catch (InvalidArgumentException) {
            $rejected = true;
        }
        $assert($rejected, "Unsafe alert origin {$unsafeOrigin} is rejected.");
    }
} finally {
    foreach ([$configPath, $templatePath, $workingPath, $configDirectory . '/target.php'] as $path) {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
        }
    }
    @rmdir($configDirectory);
    @rmdir($root);
}

echo "\n{$passed} passed, {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
