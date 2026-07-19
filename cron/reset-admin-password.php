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

if ($email === '') {
    fwrite(STDERR, "Usage: php cron/reset-admin-password.php email@example.com\n");
    exit(2);
}

$users = new UserRepository(Database::connect($config));
$user = $users->findActiveByEmail($email);

if ($user === null) {
    fwrite(STDERR, "No active administrator account matches that email.\n");
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

$password = $readPassword('New password: ');
$confirmation = $readPassword('Confirm new password: ');
$violations = (new PasswordPolicy())->violations($password);

if (!hash_equals($password, $confirmation)) {
    $violations[] = 'Password confirmation does not match.';
}

if ($violations !== []) {
    foreach ($violations as $violation) {
        fwrite(STDERR, "- {$violation}\n");
    }
    exit(1);
}

$users->updatePasswordHash($user->id, (new PasswordHasher())->hash($password));
fwrite(STDOUT, "Administrator password reset successfully.\n");
