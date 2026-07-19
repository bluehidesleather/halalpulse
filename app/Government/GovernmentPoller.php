<?php

declare(strict_types=1);

namespace HalalPulse\Government;

use DateTimeImmutable;
use HalalPulse\Support\JsonLogger;
use RuntimeException;
use Throwable;

final readonly class GovernmentPoller
{
    public function __construct(
        private GovernmentAnnouncementStore $store,
        private GovernmentSectorClassifier $classifier,
        private JsonLogger $logger,
        private int $intervalSeconds,
    ) {
    }

    /** @return array{source:string,status:string,seen:int,inserted:int,candidates:int} */
    public function poll(GovernmentSource $source): array
    {
        $name = $source->source();
        if (!$this->store->acquireLock($name)) {
            return ['source' => $name, 'status' => 'skipped', 'seen' => 0, 'inserted' => 0, 'candidates' => 0];
        }
        $runId = 0;
        $started = hrtime(true);
        try {
            $runId = $this->store->startPollRun($name);
            if (!$this->store->isDue($name, $this->intervalSeconds)) {
                $this->store->skipPollRun($runId, 'The configured source interval has not elapsed.');
                return ['source' => $name, 'status' => 'skipped', 'seen' => 0, 'inserted' => 0, 'candidates' => 0];
            }
            $announcements = $source->fetchLatest($this->store->checkpoint($name));
            $counts = $this->store->storeBatch($announcements, $this->classifier);
            $latest = $this->latest($announcements);
            $this->store->saveSuccessfulCheckpoint($name, $latest);
            $duration = $this->durationMs($started);
            $this->store->completePollRun($runId, $counts, $duration);
            $result = ['source' => $name, 'status' => 'succeeded'] + $counts;
            $this->logger->info('Government announcement poll completed.', $result + ['duration_ms' => $duration]);
            return $result;
        } catch (Throwable $exception) {
            $duration = $this->durationMs($started);
            if ($runId > 0) {
                $this->store->failPollRun($runId, $exception->getMessage(), $duration);
            }
            $this->store->recordFailure($name, $exception->getMessage());
            $this->logger->error('Government announcement poll failed.', ['source' => $name, 'duration_ms' => $duration, 'exception' => $exception::class]);
            throw new RuntimeException("{$name} government announcement poll failed.", 0, $exception);
        } finally {
            $this->store->releaseLock($name);
        }
    }

    /** @param list<GovernmentAnnouncement> $announcements */
    private function latest(array $announcements): ?DateTimeImmutable
    {
        $latest = null;
        foreach ($announcements as $announcement) {
            if ($latest === null || $announcement->publishedAt > $latest) {
                $latest = $announcement->publishedAt;
            }
        }
        return $latest;
    }

    private function durationMs(int $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }
}
