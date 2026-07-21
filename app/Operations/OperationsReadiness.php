<?php

declare(strict_types=1);

namespace HalalPulse\Operations;

use DateTimeImmutable;
use DateTimeZone;
use HalalPulse\Config;

final class OperationsReadiness
{
    /**
     * @param array<string, mixed> $snapshot
     * @param array<string, mixed>|null $backupStatus
     * @return array{
     *   fully_operational: bool,
     *   gates: array<string, bool>,
     *   checks: list<array{key: string, category: string, label: string, status: string, detail: string}>,
     *   blockers: list<string>,
     *   warnings: list<string>
     * }
     */
    public function assess(
        Config $config,
        array $snapshot,
        ?array $backupStatus,
        ?DateTimeImmutable $now = null,
    ): array {
        $timezone = new DateTimeZone((string) $config->get('app.timezone', 'Asia/Kolkata'));
        $now ??= new DateTimeImmutable('now', $timezone);
        $checks = [];
        $blockers = [];
        $warnings = [];

        $add = static function (
            string $key,
            string $category,
            string $label,
            bool $passed,
            string $detail,
            bool $blocking = true,
        ) use (&$checks, &$blockers, &$warnings): void {
            $checks[] = [
                'key' => $key,
                'category' => $category,
                'label' => $label,
                'status' => $passed ? 'passed' : ($blocking ? 'blocked' : 'warning'),
                'detail' => $detail,
            ];
            if (!$passed) {
                if ($blocking) {
                    $blockers[] = $detail;
                } else {
                    $warnings[] = $detail;
                }
            }
        };

        $production = (string) $config->get('app.environment', '') === 'production';
        $add('production_environment', 'runtime', 'Production environment', $production, $production
            ? 'Application environment is production.'
            : 'Set app.environment to production in private configuration.');

        $https = $config->get('app.force_https', false) === true;
        $add('https_enforced', 'runtime', 'HTTPS enforcement', $https, $https
            ? 'HTTPS and secure session cookies are enforced.'
            : 'Enable app.force_https before production use.');

        $activeAdmins = (int) ($snapshot['active_admins'] ?? 0);
        $add('active_admin', 'runtime', 'Active administrator', $activeAdmins > 0, $activeAdmins > 0
            ? "{$activeAdmins} active administrator account(s) are available."
            : 'At least one active administrator account is required.');

        $baseUrl = trim((string) $config->get('alerts.app_base_url', ''));
        $baseHost = strtolower((string) parse_url($baseUrl, PHP_URL_HOST));
        $validBaseUrl = filter_var($baseUrl, FILTER_VALIDATE_URL) !== false
            && strtolower((string) parse_url($baseUrl, PHP_URL_SCHEME)) === 'https'
            && $baseHost !== ''
            && !str_ends_with($baseHost, '.example');
        $add('application_url', 'runtime', 'Application URL', $validBaseUrl, $validBaseUrl
            ? "Configured HTTPS application URL: {$baseUrl}"
            : 'Configure alerts.app_base_url with the permanent HTTPS HalalPulse URL.');
        if ($validBaseUrl && str_contains($baseHost, 'hostingersite.com')) {
            $warnings[] = 'The application still uses a temporary Hostinger domain; replace it before relying on browser reputation and alert links.';
            $checks[] = [
                'key' => 'permanent_domain',
                'category' => 'runtime',
                'label' => 'Permanent domain',
                'status' => 'warning',
                'detail' => 'A temporary Hostinger domain is configured.',
            ];
        }

        $integratedEnabled = $config->get('sources.nse_integrated_rss.enabled', false) === true;
        $integrated = is_array($snapshot['nse_integrated'] ?? null) ? $snapshot['nse_integrated'] : [];
        $integratedStatus = (string) ($integrated['status'] ?? '');
        $integratedAt = (string) ($integrated['finished_at'] ?? $integrated['started_at'] ?? '');
        $integratedFresh = $integratedEnabled
            && in_array($integratedStatus, ['succeeded', 'skipped'], true)
            && $this->isFresh($integratedAt, 20 * 60, $now, $timezone);
        $add('nse_integrated_fresh', 'ingestion', 'NSE integrated RSS', $integratedFresh, $integratedFresh
            ? "Latest NSE integrated run is {$integratedStatus} and fresh."
            : 'The enabled NSE integrated RSS worker must have a successful or cooldown-skipped run within 20 minutes.');

        $failedItems = (int) ($integrated['failed_items'] ?? 0);
        $add('nse_integrated_failures', 'ingestion', 'NSE integrated failures', $failedItems === 0, $failedItems === 0
            ? 'No NSE integrated feed items are failed.'
            : "Resolve {$failedItems} failed NSE integrated feed item(s).", $failedItems > 0);
        $pendingItems = (int) ($integrated['pending_items'] ?? 0);
        if ($pendingItems > 0) {
            $warnings[] = "{$pendingItems} NSE integrated item(s) remain pending or processing.";
        }

        $legacySources = is_array($snapshot['legacy_sources'] ?? null) ? $snapshot['legacy_sources'] : [];
        foreach (['nse' => 'NSE legacy announcements', 'bse' => 'BSE announcements'] as $key => $label) {
            $enabled = $config->get("sources.{$key}.enabled", false) === true;
            $row = is_array($legacySources[$key] ?? null) ? $legacySources[$key] : [];
            if (!$enabled) {
                $add("{$key}_legacy", 'ingestion', $label, false, "{$label} coverage is disabled until its production-host probe succeeds.", false);
                continue;
            }
            $fresh = in_array((string) ($row['last_run_status'] ?? ''), ['succeeded', 'skipped'], true)
                && (int) ($row['consecutive_failures'] ?? 0) === 0
                && $this->isFresh((string) ($row['last_successful_poll_at'] ?? $row['last_run_at'] ?? ''), 3 * 3600, $now, $timezone);
            $add("{$key}_legacy", 'ingestion', $label, $fresh, $fresh
                ? "{$label} is enabled and fresh."
                : "{$label} is enabled but does not have a healthy successful poll within three hours.");
        }

        $governmentRows = is_array($snapshot['government_sources'] ?? null) ? $snapshot['government_sources'] : [];
        $enabledGovernment = 0;
        $healthyGovernment = 0;
        foreach (['pib' => 'PIB', 'sebi' => 'SEBI', 'rbi' => 'RBI', 'mca' => 'MCA', 'budget' => 'Union Budget'] as $key => $label) {
            if ($config->get("government_sources.{$key}.enabled", false) !== true) {
                continue;
            }
            $enabledGovernment++;
            $row = is_array($governmentRows[$key] ?? null) ? $governmentRows[$key] : [];
            $fresh = in_array((string) ($row['last_run_status'] ?? ''), ['succeeded', 'skipped'], true)
                && (int) ($row['consecutive_failures'] ?? 0) === 0
                && $this->isFresh((string) ($row['last_successful_poll_at'] ?? $row['last_run_at'] ?? ''), 3 * 3600, $now, $timezone);
            if ($fresh) {
                $healthyGovernment++;
            }
            $add("government_{$key}", 'ingestion', "{$label} source", $fresh, $fresh
                ? "{$label} source is enabled and fresh."
                : "{$label} source is enabled but is not healthy within the three-hour window.");
        }
        if ($enabledGovernment === 0) {
            $add('government_sources', 'ingestion', 'Government sources', false, 'Enable at least one successfully probed official government source before macro-tailwind scoring.', false);
        }

        $policyVersion = (string) ($snapshot['sharia_policy_version'] ?? '');
        $policyReady = $policyVersion !== '';
        $add('active_sharia_policy', 'research', 'Active Sharia policy', $policyReady, $policyReady
            ? "Active verified policy: {$policyVersion}"
            : 'Install an independently verified, clause-level Sharia policy before screening.');

        $methodologyVersion = (string) ($snapshot['methodology_version'] ?? '');
        $methodologyReady = $methodologyVersion !== '';
        $add('active_methodology', 'research', 'Active multibagger methodology', $methodologyReady, $methodologyReady
            ? "Active reviewed methodology: {$methodologyVersion}"
            : 'Install an independently reviewed multibagger methodology before scoring.');

        $pendingCandidates = (int) ($snapshot['pending_sharia_candidates'] ?? 0);
        if ($pendingCandidates > 0) {
            $warnings[] = "{$pendingCandidates} structured Sharia evidence candidate(s) await human review.";
        }
        if ((int) ($snapshot['activity_reviewed_companies'] ?? 0) === 0) {
            $warnings[] = 'No company business-activity review has been recorded yet.';
        }

        $backupEnabled = $config->get('backups.enabled', false) === true;
        $passphraseReady = strlen((string) $config->get('backups.encryption_passphrase', '')) >= 20;
        $backupFresh = false;
        if ($backupEnabled && $passphraseReady && is_array($backupStatus)) {
            $backupPath = (string) ($backupStatus['path'] ?? '');
            $backupHash = (string) ($backupStatus['encrypted_sha256'] ?? '');
            $maximumHours = max(1, min(720, (int) $config->get('backups.maximum_age_hours', 30)));
            $backupFresh = is_file($backupPath)
                && is_readable($backupPath)
                && strlen($backupHash) === 64
                && hash_equals($backupHash, (string) hash_file('sha256', $backupPath))
                && $this->isFresh((string) ($backupStatus['created_at'] ?? ''), $maximumHours * 3600, $now, new DateTimeZone('UTC'));
        }
        $add('encrypted_backup', 'continuity', 'Encrypted backup', $backupFresh, $backupFresh
            ? 'A recent encrypted and hash-verified backup is available.'
            : 'Enable encrypted backups, configure a private passphrase, and create a verified backup within the allowed age.');

        $alertsEnabled = $config->get('alerts.enabled', false) === true;
        $tokenReady = strlen(trim((string) $config->get('alerts.telegram.bot_token', ''))) >= 20;
        $recipientCount = (int) ($snapshot['active_alert_recipients'] ?? 0);
        $unknownDeliveries = (int) ($snapshot['unknown_alert_deliveries'] ?? 0);
        $alertsReady = $alertsEnabled && $tokenReady && $validBaseUrl && $recipientCount > 0 && $unknownDeliveries === 0;
        $add('telegram_alerts', 'alerts', 'Telegram alerts', $alertsReady, $alertsReady
            ? "Telegram alerts are enabled for {$recipientCount} consenting active recipient(s)."
            : 'Telegram alerts require an enabled private token, permanent HTTPS URL, consenting active recipient, and no unresolved unknown delivery.', false);
        if ($unknownDeliveries > 0) {
            $blockers[] = "Resolve {$unknownDeliveries} unknown Telegram delivery outcome(s) before automatic alerting.";
            $alertsReady = false;
        }

        $runtimeReady = $production && $https && $activeAdmins > 0 && $validBaseUrl;
        $ingestionReady = $integratedFresh && $failedItems === 0;
        foreach (['nse', 'bse'] as $key) {
            if ($config->get("sources.{$key}.enabled", false) === true) {
                $row = is_array($legacySources[$key] ?? null) ? $legacySources[$key] : [];
                $ingestionReady = $ingestionReady
                    && in_array((string) ($row['last_run_status'] ?? ''), ['succeeded', 'skipped'], true)
                    && (int) ($row['consecutive_failures'] ?? 0) === 0;
            }
        }
        if ($enabledGovernment > 0) {
            $ingestionReady = $ingestionReady && $healthyGovernment === $enabledGovernment;
        }

        $gates = [
            'runtime' => $runtimeReady,
            'ingestion' => $ingestionReady,
            'screening' => $policyReady,
            'ranking' => $policyReady && $methodologyReady && $enabledGovernment > 0 && $healthyGovernment === $enabledGovernment,
            'backups' => $backupFresh,
            'alerts' => $alertsReady,
        ];

        return [
            'fully_operational' => !in_array(false, $gates, true),
            'gates' => $gates,
            'checks' => $checks,
            'blockers' => array_values(array_unique($blockers)),
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    private function isFresh(
        string $dateTime,
        int $maximumSeconds,
        DateTimeImmutable $now,
        DateTimeZone $timezone,
    ): bool {
        if ($dateTime === '') {
            return false;
        }
        try {
            $value = new DateTimeImmutable($dateTime, $timezone);
        } catch (\Throwable) {
            return false;
        }
        $age = $now->getTimestamp() - $value->getTimestamp();

        return $age >= -300 && $age <= $maximumSeconds;
    }
}
