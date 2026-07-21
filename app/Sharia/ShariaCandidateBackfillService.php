<?php

declare(strict_types=1);

namespace HalalPulse\Sharia;

use HalalPulse\Nse\IntegratedFinancialResult;
use PDO;
use Throwable;

final readonly class ShariaCandidateBackfillService
{
    public function __construct(
        private PDO $pdo,
        private NseShariaEvidenceMapper $mapper,
        private ShariaInputCandidateStore $store,
    ) {
    }

    /** @return array{scanned: int, mapped: int, stored: int, failed: int} */
    public function run(int $limit = 500): array
    {
        $limit = max(1, min(5000, $limit));
        $statement = $this->pdo->query(
            <<<'SQL'
            SELECT
                fr.integrated_item_id,
                fr.period_end,
                fr.currency,
                c.symbol,
                c.company_name,
                nii.taxonomy_uri
            FROM financial_results fr
            INNER JOIN companies c ON c.id = fr.company_id
            INNER JOIN nse_integrated_feed_items nii ON nii.id = fr.integrated_item_id
            WHERE nii.status = 'processed'
            ORDER BY fr.id DESC
            LIMIT
            SQL
            . ' ' . $limit
        );

        $factStatement = $this->pdo->prepare(
            <<<'SQL'
            SELECT fact_name, context_ref, unit_ref, decimals_value, fact_value, occurrence
            FROM xbrl_facts
            WHERE integrated_item_id = :integrated_item_id
            ORDER BY id
            SQL
        );

        $counts = ['scanned' => 0, 'mapped' => 0, 'stored' => 0, 'failed' => 0];
        foreach ($statement->fetchAll() as $row) {
            $counts['scanned']++;

            try {
                $itemId = (int) $row['integrated_item_id'];
                $factStatement->execute(['integrated_item_id' => $itemId]);
                $facts = array_map(
                    static fn (array $fact): array => [
                        'name' => (string) $fact['fact_name'],
                        'context_ref' => (string) $fact['context_ref'],
                        'unit_ref' => $fact['unit_ref'] === null ? null : (string) $fact['unit_ref'],
                        'decimals' => $fact['decimals_value'] === null ? null : (string) $fact['decimals_value'],
                        'value' => (string) $fact['fact_value'],
                        'occurrence' => (int) $fact['occurrence'],
                    ],
                    $factStatement->fetchAll(),
                );
                if ($facts === []) {
                    continue;
                }

                $result = new IntegratedFinancialResult(
                    taxonomyUri: (string) ($row['taxonomy_uri'] ?? ''),
                    metadata: [
                        'symbol' => (string) $row['symbol'],
                        'company_name' => (string) $row['company_name'],
                        'period_end' => (string) $row['period_end'],
                        'currency' => (string) $row['currency'],
                    ],
                    metrics: [],
                    facts: $facts,
                );
                $candidates = $this->mapper->map($result);
                $counts['mapped'] += count($candidates);
                $counts['stored'] += $this->store->store($itemId, $result, $candidates);
            } catch (Throwable) {
                $counts['failed']++;
            }
        }

        return $counts;
    }
}
