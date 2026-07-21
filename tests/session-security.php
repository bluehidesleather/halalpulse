#!/usr/bin/env php
<?php

declare(strict_types=1);

use HalalPulse\Auth\SessionSecurityPolicy;

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

$policy = new SessionSecurityPolicy(
    idleSeconds: 1800,
    absoluteSeconds: 43200,
    rotationSeconds: 900,
);
$now = 2_000_000_000;

$anonymous = $policy->evaluate(null, $now);
$assert(!$anonymous['authenticated'] && !$anonymous['expired'] && !$anonymous['rotate'], 'Anonymous sessions do not enter authenticated timing gates.');

$recent = $policy->evaluate([
    'created_at' => $now - 600,
    'last_activity_at' => $now - 60,
    'last_regenerated_at' => $now - 300,
], $now);
$assert($recent['authenticated'] && !$recent['expired'] && !$recent['rotate'], 'A recent active session remains valid without premature rotation.');

$idleBoundary = $policy->evaluate([
    'created_at' => $now - 3000,
    'last_activity_at' => $now - 1800,
    'last_regenerated_at' => $now - 100,
], $now);
$assert(!$idleBoundary['expired'], 'The exact idle boundary remains valid.');

$idleExpired = $policy->evaluate([
    'created_at' => $now - 3000,
    'last_activity_at' => $now - 1801,
    'last_regenerated_at' => $now - 100,
], $now);
$assert($idleExpired['expired'] && $idleExpired['reason'] === 'idle_timeout', 'An idle session expires immediately after the configured boundary.');

$absoluteExpired = $policy->evaluate([
    'created_at' => $now - 43201,
    'last_activity_at' => $now - 10,
    'last_regenerated_at' => $now - 10,
], $now);
$assert($absoluteExpired['expired'] && $absoluteExpired['reason'] === 'absolute_timeout', 'Recent activity cannot extend the absolute authentication lifetime.');

$rotationDue = $policy->evaluate([
    'created_at' => $now - 3600,
    'last_activity_at' => $now - 10,
    'last_regenerated_at' => $now - 900,
], $now);
$assert(!$rotationDue['expired'] && $rotationDue['rotate'], 'An active authenticated session rotates at the configured interval.');

$legacySession = $policy->evaluate([
    'created_at' => $now - 1200,
    'last_activity_at' => $now - 10,
], $now);
$assert($legacySession['rotate'], 'Sessions created before the rotation field existed use creation time and rotate safely.');

$futureTimestamp = $policy->evaluate([
    'created_at' => $now + 1,
    'last_activity_at' => $now,
    'last_regenerated_at' => $now,
], $now);
$assert($futureTimestamp['expired'] && $futureTimestamp['reason'] === 'invalid_timestamps', 'Impossible future authentication timestamps fail closed.');

$contradictoryTimestamp = $policy->evaluate([
    'created_at' => $now - 100,
    'last_activity_at' => $now - 10,
    'last_regenerated_at' => $now - 200,
], $now);
$assert($contradictoryTimestamp['expired'] && $contradictoryTimestamp['reason'] === 'invalid_timestamps', 'A regeneration timestamp before authentication creation fails closed.');

$disabledRotation = (new SessionSecurityPolicy(1800, 43200, 0))->evaluate([
    'created_at' => $now - 1200,
    'last_activity_at' => $now - 10,
    'last_regenerated_at' => $now - 1200,
], $now);
$assert(!$disabledRotation['expired'] && !$disabledRotation['rotate'], 'A zero rotation interval explicitly disables periodic rotation without disabling expiry.');

echo "\n{$passed} passed, {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
