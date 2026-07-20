<?php

declare(strict_types=1);

namespace HalalPulse\Dashboard;

use PDO;

final class DashboardRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{companies: int, filings: int, candidates: int, pending: int} */
    public function summary(): array
    {
        $row = $this->pdo->query(
            <<<'SQL'
            SELECT
                (SELECT COUNT(*) FROM companies WHERE is_active = 1) AS companies,
                (SELECT COUNT(*) FROM filings) AS filings,
                (SELECT COUNT(*) FROM filings WHERE is_quarterly_result_candidate = 1) AS candidates,
                (SELECT COUNT(*) FROM filings WHERE processing_status IN ('detected', 'queued')) AS pending
            SQL
        )->fetch();

        return [
            'companies' => (int) ($row['companies'] ?? 0),
            'filings' => (int) ($row['filings'] ?? 0),
            'candidates' => (int) ($row['candidates'] ?? 0),
            'pending' => (int) ($row['pending'] ?? 0),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function latestFilings(int $limit = 10): array
    {
        $limit = max(1, min(25, $limit));
        $statement = $this->pdo->query(
            <<<'SQL'
            SELECT
                f.id,
                f.exchange,
                c.symbol,
                c.company_name,
                f.subject,
                f.announced_at,
                f.is_quarterly_result_candidate,
                f.classifier_confidence,
                f.processing_status
            FROM filings f
            INNER JOIN companies c ON c.id = f.company_id
            ORDER BY f.announced_at DESC, f.id DESC
            LIMIT
            SQL
            . ' ' . $limit
        );

        return $statement->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function sourceStatuses(): array
    {
        $statement = $this->pdo->query(
            <<<'SQL'
            SELECT
                sc.exchange,
                sc.last_successful_announcement_at,
                sc.last_successful_poll_at,
                sc.consecutive_failures,
                sc.last_error,
                pr.status AS last_run_status,
                pr.started_at AS last_run_started_at,
                pr.records_inserted AS last_run_inserted
            FROM source_checkpoints sc
            LEFT JOIN poll_runs pr ON pr.id = (
                SELECT MAX(pr2.id) FROM poll_runs pr2 WHERE pr2.exchange = sc.exchange
            )
            ORDER BY sc.exchange
            SQL
        );

        return $statement->fetchAll();
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int, page: int, pages: int}
     */
    public function searchFilings(
        string $query,
        string $exchange,
        string $candidate,
        string $status,
        int $page,
        int $perPage = 25,
    ): array {
        $where = [];
        $params = [];
        $query = mb_substr(trim($query), 0, 100);

        if ($query !== '') {
            $where[] = '(c.symbol LIKE :query_symbol OR c.company_name LIKE :query_company OR f.subject LIKE :query_subject)';
            $params['query_symbol'] = '%' . $query . '%';
            $params['query_company'] = '%' . $query . '%';
            $params['query_subject'] = '%' . $query . '%';
        }

        if (in_array($exchange, ['NSE', 'BSE'], true)) {
            $where[] = 'f.exchange = :exchange';
            $params['exchange'] = $exchange;
        }

        if (in_array($candidate, ['yes', 'no'], true)) {
            $where[] = 'f.is_quarterly_result_candidate = :candidate';
            $params['candidate'] = $candidate === 'yes' ? 1 : 0;
        }

        $allowedStatuses = ['detected', 'queued', 'processed', 'rejected', 'failed'];
        if (in_array($status, $allowedStatuses, true)) {
            $where[] = 'f.processing_status = :status';
            $params['status'] = $status;
        }

        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $count = $this->pdo->prepare(
            'SELECT COUNT(*) FROM filings f INNER JOIN companies c ON c.id = f.company_id' . $whereSql
        );
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $perPage = max(10, min(100, $perPage));
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $pages));
        $offset = ($page - 1) * $perPage;

        $statement = $this->pdo->prepare(
            <<<'SQL'
            SELECT
                f.id,
                f.exchange,
                c.symbol,
                c.company_name,
                f.category,
                f.subject,
                f.announced_at,
                f.attachment_url,
                f.is_quarterly_result_candidate,
                f.classifier_confidence,
                f.classifier_reason,
                f.processing_status
            FROM filings f
            INNER JOIN companies c ON c.id = f.company_id
            SQL
            . $whereSql
            . ' ORDER BY f.announced_at DESC, f.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset
        );
        $statement->execute($params);

        return [
            'items' => $statement->fetchAll(),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
        ];
    }
}
