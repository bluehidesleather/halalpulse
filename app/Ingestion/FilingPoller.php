<?php

declare(strict_types=1);

namespace HalalPulse\Ingestion;

use DateTimeImmutable;
use HalalPulse\Support\JsonLogger;
use RuntimeException;
use Throwable;

final class FilingPoller
{
    public function __construct(
        private readonly FilingStore $store,
        private readonly QuarterlyResultClassifier $classifier,
        private readonly JsonLogger $logger,
    ) {
    }

    /** @return array{exchange: string, status: string, seen: int, inserted: int, candidates: int} */
    public function poll(FilingSource $source): array
    {
        $exchange = $source->exchange();
        if (!$this->store->acquireLock($exchange)) {
            $this->logger->info('Filing poll skipped because another run holds the lock.', [
                'exchange' => $exchange,
            ]);

            return [
                'exchange' => $exchange,
                'status' => 'skipped',
                'seen' => 0,
                'inserted' => 0,
                'candidates' => 0,
            ];
        }

        $runId = 0;
        $startedAt = hrtime(true);

        try {
            $runId = $this->store->startPollRun($exchange);
            $checkpoint = $this->store->checkpoint($exchange);
            $filings = $source->fetchLatest($checkpoint);
            $counts = $this->store->storeBatch($filings, $this->classifier);

            $latest = $this->latestAnnouncementTime($filings);
            $this->store->saveSuccessfulCheckpoint($exchange, $latest);

            $durationMs = $this->durationMs($startedAt);
            $this->store->completePollRun($runId, $counts, $durationMs);
            $result = ['exchange' => $exchange, 'status' => 'succeeded'] + $counts;
            $this->logger->info('Filing poll completed.', $result + ['duration_ms' => $durationMs]);

            return $result;
        } catch (Throwable $exception) {
            $durationMs = $this->durationMs($startedAt);

            if ($runId > 0) {
                $this->store->failPollRun($runId, $exception->getMessage(), $durationMs);
            }

            $this->store->recordSourceFailure($exchange, $exception->getMessage());
            $this->logger->error('Filing poll failed.', [
                'exchange' => $exchange,
                'duration_ms' => $durationMs,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            throw new RuntimeException("{$exchange} filing poll failed.", 0, $exception);
        } finally {
            $this->store->releaseLock($exchange);
        }
    }

    /** @param list<Filing> $filings */
    private function latestAnnouncementTime(array $filings): ?DateTimeImmutable
    {
        $latest = null;

        foreach ($filings as $filing) {
            if ($latest === null || $filing->announcedAt > $latest) {
                $latest = $filing->announcedAt;
            }
        }

        return $latest;
    }

    private function durationMs(int $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }
}
