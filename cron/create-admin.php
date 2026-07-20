#!/usr/bin/env php
<?php

declare(strict_types=1);

use HalalPulse\Auth\PasswordHasher;
use HalalPulse\Auth\PasswordPolicy;
use HalalPulse\Auth\UserRepository;
use HalalPulse\Database;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$config = require dirname(__DIR__) . '/app/bootstrap.php';
$email = isset($argv[1]) ? trim((string) $argv[1]) : '';
$displayName = isset($argv[2]) ? trim((string) $argv[2]) : '';

if ($email === '' || $displayName === '') {
    fwrite(STDERR, "Usage: php cron/create-admin.php email@example.com \"Display Name\"\n");
    exit(2);
}

$users = new UserRepository(Database::connect($config));
if ($users->activeAdminCount() > 0) {
    fwrite(STDERR, "An active administrator already exists. This personal installation permits one active administrator.\n");
    exit(1);
}

if ($users->emailExists($email)) {
    fwrite(STDERR, "An account with that email already exists. Use reset-admin-password.php instead.\n");
    exit(1);
}

$readPassword = static function (string $prompt): string {
    fwrite(STDOUT, $prompt);
    $mode = null;

    if (function_exists('shell_exec')) {
        $candidate = shell_exec('stty -g 2>/dev/null');
        $mode = is_string($candidate) && trim($candidate) !== '' ? trim($candidate) : null;
    }

    if ($mode !== null) {
        shell_exec('stty -echo');
    }

    try {
        $value = fgets(STDIN);
    } finally {
        if ($mode !== null) {
            shell_exec('stty ' . escapeshellarg($mode));
            fwrite(STDOUT, PHP_EOL);
        }
    }

    return is_string($value) ? rtrim($value, "\r\n") : '';
};

$password = $readPassword('New administrator password: ');
$confirmation = $readPassword('Confirm administrator password: ');
$policy = new PasswordPolicy();
$violations = $policy->violations($password);

if (!hash_equals($password, $confirmation)) {
    $violations[] = 'Password confirmation does not match.';
}

if ($violations !== []) {
    foreach ($violations as $violation) {
        fwrite(STDERR, "- {$violation}\n");
    }
    exit(1);
}

$userId = $users->createAdmin($email, $displayName, (new PasswordHasher())->hash($password));
fwrite(STDOUT, "Administrator created with user ID {$userId}.\n");
