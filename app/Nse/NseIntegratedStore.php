<?php

declare(strict_types=1);

namespace HalalPulse\Nse;

use DateTimeImmutable;
use PDO;
use Throwable;

final class NseIntegratedStore
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function acquireLock(): bool
    {
        $value = $this->pdo->query("SELECT GET_LOCK('halalpulse:nse:integrated-rss', 0)")->fetchColumn();

        return (int) $value === 1;
    }

    public function releaseLock(): void
    {
        $this->pdo->query("SELECT RELEASE_LOCK('halalpulse:nse:integrated-rss')")->fetchColumn();
    }

    public function startRun(string $triggerType, ?int $syncRequestId): int
    {
        if (!in_array($triggerType, ['scheduled', 'manual'], true)) {
            throw new \InvalidArgumentException('NSE sync trigger must be scheduled or manual.');
        }

        $statement = $this->pdo->prepare(
            <<<'SQL'
            INSERT INTO nse_integrated_sync_runs (trigger_type, sync_request_id, status, started_at)
            VALUES (:trigger_type, :sync_request_id, 'running', CURRENT_TIMESTAMP)
            SQL
        );
        $statement->execute([
            'trigger_type' => $triggerType,
            'sync_request_id' => $syncRequestId,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function recordFeed(int $runId, IntegratedFeed $feed, ArchivedXml $archive, int $discovered): void
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
            UPDATE nse_integrated_sync_runs
            SET feed_last_build_at = :last_build_at,
                feed_sha256 = :sha256,
                feed_storage_path = :storage_path,
                feed_items = :feed_items,
                items_discovered = :discovered
            WHERE id = :id
            SQL
        );
        $statement->execute([
            'last_build_at' => $feed->lastBuildAt->format('Y-m-d H:i:s'),
            'sha256' => $archive->sha256,
            'storage_path' => $archive->relativePath,
            'feed_items' => $feed->sourceRows,
            'discovered' => $discovered,
            'id' => $runId,
        ]);
    }

    public function discover(IntegratedFeed $feed): int
    {
        $insert = $this->pdo->prepare(
            <<<'SQL'
            INSERT IGNORE INTO nse_integrated_feed_items (
                source_url,
                source_filename,
                company_name,
                description,
                filing_type,
                revision_note,
                published_at,
                item_hash,
                raw_item,
                status,
                first_discovered_at,
                last_seen_at
            ) VALUES (
                :source_url,
                :source_filename,
                :company_name,
                :description,
                :filing_type,
                :revision_note,
                :published_at,
                :item_hash,
                :raw_item,
                'pending',
                CURRENT_TIMESTAMP,
                CURRENT_TIMESTAMP
            )
            SQL
        );
        $touch = $this->pdo->prepare(
            <<<'SQL'
            UPDATE nse_integrated_feed_items
            SET last_seen_at = CURRENT_TIMESTAMP,
                description = :description,
                revision_note = :revision_note,
                raw_item = :raw_item,
                item_hash = :item_hash
            WHERE source_url = :source_url
            SQL
        );
        $discovered = 0;

        foreach ($feed->items as $item) {
            $raw = json_encode(
                $item->rawPayload,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );
            $parameters = [
                'source_url' => $item->sourceUrl,
                'source_filename' => $item->sourceFilename(),
                'company_name' => $item->companyName,
                'description' => $item->description,
                'filing_type' => $item->filingType,
                'revision_note' => $item->revisionNote,
                'published_at' => $item->publishedAt->format('Y-m-d H:i:s'),
                'item_hash' => $item->itemHash(),
                'raw_item' => $raw,
            ];
            $insert->execute($parameters);
            if ($insert->rowCount() === 1) {
                $discovered++;
                continue;
            }

            $touch->execute([
                'description' => $item->description,
                'revision_note' => $item->revisionNote,
                'raw_item' => $raw,
                'item_hash' => $item->itemHash(),
                'source_url' => $item->sourceUrl,
            ]);
        }

        return $discovered;
    }

    /** @return list<QueuedIntegratedItem> */
    public function queuedItems(int $limit): array
    {
        $limit = max(1, min(50, $limit));
        $statement = $this->pdo->query(
            <<<'SQL'
            SELECT id, source_url, company_name, description, filing_type, revision_note,
                   published_at, item_hash, raw_item, attempts
            FROM nse_integrated_feed_items
            WHERE (
                    status IN ('pending', 'failed')
                    AND (next_attempt_at IS NULL OR next_attempt_at <= CURRENT_TIMESTAMP)
                  )
               OR (
                    status = 'processing'
                    AND updated_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 30 MINUTE)
                  )
            ORDER BY published_at, id
            LIMIT
            SQL
            . ' ' . $limit
        );
        $items = [];

        foreach ($statement->fetchAll() as $row) {
            $raw = json_decode((string) $row['raw_item'], true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($raw)) {
                throw new NseSourceException('Stored NSE RSS item is not a JSON object.');
            }

            $items[] = new QueuedIntegratedItem(
                id: (int) $row['id'],
                item: new IntegratedFeedItem(
                    companyName: (string) $row['company_name'],
                    sourceUrl: (string) $row['source_url'],
                    description: (string) $row['description'],
                    filingType: (string) $row['filing_type'],
                    revisionNote: (string) $row['revision_note'],
                    publishedAt: new DateTimeImmutable((string) $row['published_at']),
                    rawPayload: array_map(static fn (mixed $value): string => (string) $value, $raw),
                ),
                attempts: (int) $row['attempts'],
            );
        }

        return $items;
    }

    public function markProcessing(int $itemId): void
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
            UPDATE nse_integrated_feed_items
            SET status = 'processing', attempts = attempts + 1, last_error = NULL, updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
            SQL
        );
        $statement->execute(['id' => $itemId]);
    }

    public function recordItemFailure(QueuedIntegratedItem $queued, string $error): void
    {
        $attempt = $queued->attempts + 1;
        $retryMinutes = min(60, 2 ** min($attempt, 5));
        $nextAttemptAt = (new DateTimeImmutable())
            ->modify(sprintf('+%d minutes', $retryMinutes))
            ->format('Y-m-d H:i:s');
        $statement = $this->pdo->prepare(
            <<<'SQL'
            UPDATE nse_integrated_feed_items
            SET status = 'failed',
                next_attempt_at = :next_attempt_at,
                last_error = :last_error,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
            SQL
        );
        $statement->bindValue('next_attempt_at', $nextAttemptAt);
        $statement->bindValue('last_error', mb_substr($error, 0, 4000));
        $statement->bindValue('id', $queued->id, PDO::PARAM_INT);
        $statement->execute();
    }

    public function completeItem(
        QueuedIntegratedItem $queued,
        IntegratedFinancialResult $result,
        ArchivedXml $archive,
    ): int {
        $this->pdo->beginTransaction();

        try {
            $companyId = $this->upsertCompany($result);
            $filingId = $this->upsertFiling($queued, $result, $companyId);
            $this->upsertFinancialResult($queued->id, $filingId, $companyId, $result);
            $this->replaceFacts($queued->id, $result->facts);

            $statement = $this->pdo->prepare(
                <<<'SQL'
                UPDATE nse_integrated_feed_items
                SET status = 'processed',
                    next_attempt_at = NULL,
                    last_error = NULL,
                    filing_id = :filing_id,
                    xbrl_storage_path = :storage_path,
                    xbrl_sha256 = :sha256,
                    xbrl_size_bytes = :size_bytes,
                    taxonomy_uri = :taxonomy_uri,
                    processed_at = CURRENT_TIMESTAMP,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id
                SQL
            );
            $statement->execute([
                'filing_id' => $filingId,
                'storage_path' => $archive->relativePath,
                'sha256' => $archive->sha256,
                'size_bytes' => $archive->sizeBytes,
                'taxonomy_uri' => mb_substr($result->taxonomyUri, 0, 500),
                'id' => $queued->id,
            ]);

            $this->pdo->commit();

            return $filingId;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    /** @param array{queued: int, processed: int, failed: int} $counts */
    public function finishRun(int $runId, string $status, array $counts, int $durationMs): void
    {
        if (!in_array($status, ['succeeded', 'partial', 'skipped'], true)) {
            throw new \InvalidArgumentException('NSE sync completion status is invalid.');
        }

        $statement = $this->pdo->prepare(
            <<<'SQL'
            UPDATE nse_integrated_sync_runs
            SET status = :status,
                items_queued = :queued,
                items_processed = :processed,
                items_failed = :failed,
                duration_ms = :duration_ms,
                finished_at = CURRENT_TIMESTAMP
            WHERE id = :id
            SQL
        );
        $statement->execute([
            'status' => $status,
            'queued' => $counts['queued'],
            'processed' => $counts['processed'],
            'failed' => $counts['failed'],
            'duration_ms' => $durationMs,
            'id' => $runId,
        ]);
    }

    public function failRun(int $runId, string $error, int $durationMs): void
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
            UPDATE nse_integrated_sync_runs
            SET status = 'failed', error_message = :error, duration_ms = :duration_ms, finished_at = CURRENT_TIMESTAMP
            WHERE id = :id
            SQL
        );
        $statement->execute([
            'error' => mb_substr($error, 0, 4000),
            'duration_ms' => $durationMs,
            'id' => $runId,
        ]);
    }

    private function upsertCompany(IntegratedFinancialResult $result): int
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
            INSERT INTO companies (exchange, symbol, isin, company_name, last_seen_at)
            VALUES ('NSE', :symbol, :isin, :company_name, CURRENT_TIMESTAMP)
            ON DUPLICATE KEY UPDATE
                id = LAST_INSERT_ID(id),
                isin = COALESCE(VALUES(isin), isin),
                company_name = VALUES(company_name),
                last_seen_at = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
            SQL
        );
        $isin = trim((string) ($result->metadata['isin'] ?? ''));
        $statement->execute([
            'symbol' => $result->metadata['symbol'],
            'isin' => $isin === '' ? null : $isin,
            'company_name' => $result->metadata['company_name'],
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function upsertFiling(QueuedIntegratedItem $queued, IntegratedFinancialResult $result, int $companyId): int
    {
        $item = $queued->item;
        $subject = sprintf(
            'Integrated Filing - Financial Results (%s, %s)',
            ucfirst($item->filingType),
            ucfirst((string) $result->metadata['statement_scope']),
        );
        $raw = json_encode(
            $item->rawPayload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
        $statement = $this->pdo->prepare(
            <<<'SQL'
            INSERT INTO filings (
                company_id, exchange, source_id, category, subject, announced_at, attachment_url,
                payload_hash, raw_payload, is_quarterly_result_candidate, classifier_confidence,
                classifier_reason, processing_status, processed_at
            ) VALUES (
                :company_id, 'NSE', :source_id, 'Integrated Filing- Financials', :subject,
                :announced_at, :attachment_url, :payload_hash, :raw_payload, 1, 100,
                'Official structured NSE Integrated Filing XBRL.', 'processed', CURRENT_TIMESTAMP
            )
            ON DUPLICATE KEY UPDATE
                id = LAST_INSERT_ID(id),
                company_id = VALUES(company_id),
                subject = VALUES(subject),
                attachment_url = VALUES(attachment_url),
                processing_status = 'processed',
                processed_at = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
            SQL
        );
        $statement->execute([
            'company_id' => $companyId,
            'source_id' => $item->sourceId(),
            'subject' => $subject,
            'announced_at' => $item->publishedAt->format('Y-m-d H:i:s'),
            'attachment_url' => $item->sourceUrl,
            'payload_hash' => $item->itemHash(),
            'raw_payload' => $raw,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function upsertFinancialResult(
        int $itemId,
        int $filingId,
        int $companyId,
        IntegratedFinancialResult $result,
    ): void {
        $statement = $this->pdo->prepare(
            <<<'SQL'
            INSERT INTO financial_results (
                integrated_item_id, filing_id, company_id, scrip_code, isin, company_type, security_class,
                financial_year_start, financial_year_end, period_start, period_end, reporting_period_type,
                reporting_quarter, audit_status, statement_scope, currency, rounding_level, board_approval_date,
                revenue_from_operations, other_income, total_income, finance_costs, total_expenses,
                profit_before_tax, tax_expense, profit_for_period, profit_attributable_to_owners,
                basic_eps, diluted_eps, debt_equity_ratio
            ) VALUES (
                :integrated_item_id, :filing_id, :company_id, :scrip_code, :isin, :company_type, :security_class,
                :financial_year_start, :financial_year_end, :period_start, :period_end, :reporting_period_type,
                :reporting_quarter, :audit_status, :statement_scope, :currency, :rounding_level, :board_approval_date,
                :revenue_from_operations, :other_income, :total_income, :finance_costs, :total_expenses,
                :profit_before_tax, :tax_expense, :profit_for_period, :profit_attributable_to_owners,
                :basic_eps, :diluted_eps, :debt_equity_ratio
            )
            ON DUPLICATE KEY UPDATE
                filing_id = VALUES(filing_id), company_id = VALUES(company_id), period_end = VALUES(period_end),
                audit_status = VALUES(audit_status), statement_scope = VALUES(statement_scope),
                revenue_from_operations = VALUES(revenue_from_operations), total_income = VALUES(total_income),
                total_expenses = VALUES(total_expenses), profit_before_tax = VALUES(profit_before_tax),
                profit_for_period = VALUES(profit_for_period), basic_eps = VALUES(basic_eps),
                diluted_eps = VALUES(diluted_eps), updated_at = CURRENT_TIMESTAMP
            SQL
        );
        $statement->execute([
            'integrated_item_id' => $itemId,
            'filing_id' => $filingId,
            'company_id' => $companyId,
            'scrip_code' => $result->metadata['scrip_code'],
            'isin' => $result->metadata['isin'] ?: null,
            'company_type' => $result->metadata['company_type'],
            'security_class' => $result->metadata['security_class'],
            'financial_year_start' => $result->metadata['financial_year_start'],
            'financial_year_end' => $result->metadata['financial_year_end'],
            'period_start' => $result->metadata['period_start'],
            'period_end' => $result->metadata['period_end'],
            'reporting_period_type' => $result->metadata['reporting_period_type'],
            'reporting_quarter' => $result->metadata['reporting_quarter'],
            'audit_status' => $result->metadata['audit_status'],
            'statement_scope' => $result->metadata['statement_scope'],
            'currency' => $result->metadata['currency'] ?: 'INR',
            'rounding_level' => $result->metadata['rounding_level'],
            'board_approval_date' => $result->metadata['board_approval_date'],
        ] + $result->metrics);
    }

    /** @param list<array{name: string, context_ref: string, unit_ref: ?string, decimals: ?string, value: string, occurrence: int}> $facts */
    private function replaceFacts(int $itemId, array $facts): void
    {
        $delete = $this->pdo->prepare('DELETE FROM xbrl_facts WHERE integrated_item_id = :item_id');
        $delete->execute(['item_id' => $itemId]);
        $insert = $this->pdo->prepare(
            <<<'SQL'
            INSERT INTO xbrl_facts (
                integrated_item_id, fact_name, context_ref, unit_ref, decimals_value, fact_value, occurrence
            ) VALUES (
                :integrated_item_id, :fact_name, :context_ref, :unit_ref, :decimals_value, :fact_value, :occurrence
            )
            SQL
        );

        foreach ($facts as $fact) {
            $insert->execute([
                'integrated_item_id' => $itemId,
                'fact_name' => mb_substr($fact['name'], 0, 191),
                'context_ref' => mb_substr($fact['context_ref'], 0, 191),
                'unit_ref' => $fact['unit_ref'] === null ? null : mb_substr($fact['unit_ref'], 0, 100),
                'decimals_value' => $fact['decimals'] === null ? null : mb_substr($fact['decimals'], 0, 32),
                'fact_value' => $fact['value'],
                'occurrence' => $fact['occurrence'],
            ]);
        }
    }
}
