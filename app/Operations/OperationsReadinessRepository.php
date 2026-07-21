<?php

declare(strict_types=1);

namespace HalalPulse\Operations;

use PDO;

final readonly class OperationsReadinessRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        $summary = $this->pdo->query(
            <<<'SQL'
            SELECT
                (SELECT COUNT(*) FROM users WHERE role='admin' AND is_active=1) AS active_admins,
                (SELECT version FROM sharia_policies WHERE is_active=1 ORDER BY activated_at DESC,id DESC LIMIT 1) AS sharia_policy_version,
                (SELECT version FROM multibagger_methodologies WHERE is_active=1 ORDER BY activated_at DESC,id DESC LIMIT 1) AS methodology_version,
                (SELECT COUNT(*) FROM sharia_input_candidates WHERE review_status='pending') AS pending_sharia_candidates,
                (SELECT COUNT(DISTINCT company_id) FROM company_sharia_activity_reviews) AS activity_reviewed_companies,
                (SELECT COUNT(*) FROM sharia_screenings WHERE status='passed') AS sharia_passes,
                (SELECT COUNT(*) FROM multibagger_scores WHERE status='scored') AS completed_scores,
                (SELECT COUNT(*) FROM alert_recipients WHERE channel='telegram' AND is_active=1) AS active_alert_recipients,
                (SELECT COUNT(*) FROM alert_deliveries WHERE status='unknown') AS unknown_alert_deliveries,
                (SELECT COUNT(*) FROM alert_deliveries WHERE status='failed') AS failed_alert_deliveries
            SQL,
        )->fetch();

        $integrated = $this->pdo->query(
            <<<'SQL'
            SELECT
                (SELECT status FROM nse_integrated_sync_runs ORDER BY id DESC LIMIT 1) AS status,
                (SELECT started_at FROM nse_integrated_sync_runs ORDER BY id DESC LIMIT 1) AS started_at,
                (SELECT finished_at FROM nse_integrated_sync_runs ORDER BY id DESC LIMIT 1) AS finished_at,
                (SELECT COUNT(*) FROM nse_integrated_feed_items WHERE status='failed') AS failed_items,
                (SELECT COUNT(*) FROM nse_integrated_feed_items WHERE status IN ('pending','processing')) AS pending_items
            SQL,
        )->fetch();

        $legacy = [];
        foreach ($this->pdo->query(
            <<<'SQL'
            SELECT sc.exchange,sc.last_successful_poll_at,sc.consecutive_failures,sc.last_error,
                pr.status AS last_run_status,pr.started_at AS last_run_at
            FROM source_checkpoints sc
            LEFT JOIN poll_runs pr ON pr.id=(SELECT MAX(pr2.id) FROM poll_runs pr2 WHERE pr2.exchange=sc.exchange)
            ORDER BY sc.exchange
            SQL,
        )->fetchAll() as $row) {
            $legacy[strtolower((string) $row['exchange'])] = $row;
        }

        $government = [];
        foreach ($this->pdo->query(
            <<<'SQL'
            SELECT gsc.source,gsc.last_successful_poll_at,gsc.consecutive_failures,gsc.last_error,
                gpr.status AS last_run_status,gpr.started_at AS last_run_at
            FROM government_source_checkpoints gsc
            LEFT JOIN government_poll_runs gpr ON gpr.id=(SELECT MAX(gpr2.id) FROM government_poll_runs gpr2 WHERE gpr2.source=gsc.source)
            ORDER BY gsc.source
            SQL,
        )->fetchAll() as $row) {
            $government[strtolower((string) $row['source'])] = $row;
        }

        return [
            'active_admins' => (int) ($summary['active_admins'] ?? 0),
            'sharia_policy_version' => isset($summary['sharia_policy_version']) ? (string) $summary['sharia_policy_version'] : null,
            'methodology_version' => isset($summary['methodology_version']) ? (string) $summary['methodology_version'] : null,
            'pending_sharia_candidates' => (int) ($summary['pending_sharia_candidates'] ?? 0),
            'activity_reviewed_companies' => (int) ($summary['activity_reviewed_companies'] ?? 0),
            'sharia_passes' => (int) ($summary['sharia_passes'] ?? 0),
            'completed_scores' => (int) ($summary['completed_scores'] ?? 0),
            'active_alert_recipients' => (int) ($summary['active_alert_recipients'] ?? 0),
            'unknown_alert_deliveries' => (int) ($summary['unknown_alert_deliveries'] ?? 0),
            'failed_alert_deliveries' => (int) ($summary['failed_alert_deliveries'] ?? 0),
            'nse_integrated' => [
                'status' => isset($integrated['status']) ? (string) $integrated['status'] : null,
                'started_at' => isset($integrated['started_at']) ? (string) $integrated['started_at'] : null,
                'finished_at' => isset($integrated['finished_at']) ? (string) $integrated['finished_at'] : null,
                'failed_items' => (int) ($integrated['failed_items'] ?? 0),
                'pending_items' => (int) ($integrated['pending_items'] ?? 0),
            ],
            'legacy_sources' => $legacy,
            'government_sources' => $government,
        ];
    }
}
