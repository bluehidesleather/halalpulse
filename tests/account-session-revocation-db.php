#!/usr/bin/env php
<?php

declare(strict_types=1);

use HalalPulse\Auth\PasswordHasher;
use HalalPulse\Auth\SessionManager;
use HalalPulse\Auth\UserRepository;
use HalalPulse\Database;

$config = require dirname(__DIR__) . '/app/bootstrap.php';
$pdo = Database::connect($config);
$users = new UserRepository($pdo);
$hasher = new PasswordHasher();
$email = 'session-revocation-' . bin2hex(random_bytes(6)) . '@example.com';
$results = [];
$record = static function (bool $condition, string $message) use (&$results): void {
    $results[] = [$condition, $message];
};

$userId = $users->createAdmin($email, 'Session Revocation Test', $hasher->hash('SyntheticInitialPassphrase!42'));

try {
    $user = $users->findActiveById($userId);
    $record($user !== null && $user->authVersion === 1, 'New administrators begin at authentication version 1.');
    if ($user === null) {
        throw new RuntimeException('Unable to reload the synthetic administrator.');
    }

    ini_set('session.save_path', sys_get_temp_dir());
    $session = new SessionManager(
        name: 'halalpulse_test_' . bin2hex(random_bytes(6)),
        idleSeconds: 1800,
        absoluteSeconds: 43200,
        secureCookie: false,
        rotationSeconds: 900,
    );
    $session->start();
    $session->login($user);
    $record($session->currentUser($users)?->id === $userId, 'A newly issued session resolves the active administrator.');

    $users->rehashPasswordHash($userId, $hasher->hash('SyntheticInitialPassphrase!42'));
    $afterRehash = $users->findActiveById($userId);
    $record($afterRehash !== null && $afterRehash->authVersion === 1, 'A transparent password-hash upgrade does not revoke sessions.');
    $record($session->currentUser($users)?->id === $userId, 'The issued session remains valid after a transparent hash upgrade.');

    $users->updatePasswordHash($userId, $hasher->hash('SyntheticChangedPassphrase!84'));
    $afterChange = $users->findActiveById($userId);
    $record($afterChange !== null && $afterChange->authVersion === 2, 'An intentional password change increments the authentication version.');
    $record($session->currentUser($users) === null, 'A session issued under the previous authentication version is revoked immediately.');
    $flash = $session->consumeFlash();
    $record(is_array($flash) && ($flash['type'] ?? null) === 'warning', 'Session revocation leaves a safe sign-in warning for the administrator.');

    if ($afterChange !== null) {
        $session->login($afterChange);
        $record($session->currentUser($users)?->authVersion === 2, 'A fresh login after the credential change uses the new authentication version.');
    }

    $session->logout();
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];
        session_destroy();
    }
    $statement = $pdo->prepare('DELETE FROM users WHERE id = :id');
    $statement->execute(['id' => $userId]);
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
