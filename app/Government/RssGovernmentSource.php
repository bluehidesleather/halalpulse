<?php

declare(strict_types=1);

namespace HalalPulse\Government;

use DateTimeImmutable;
use HalalPulse\Http\HttpClient;
use HalalPulse\Support\JsonLogger;

final readonly class RssGovernmentSource implements GovernmentSource
{
    public function __construct(
        private string $sourceName,
        private string $endpoint,
        private HttpClient $http,
        private RssAnnouncementMapper $mapper,
        private JsonLogger $logger,
    ) {
    }

    public function source(): string
    {
        return $this->sourceName;
    }

    public function fetchLatest(?DateTimeImmutable $checkpoint): array
    {
        $response = $this->http->get($this->endpoint, ['Accept' => 'application/rss+xml, application/atom+xml, application/xml, text/xml;q=0.9']);
        $result = $this->mapper->map($response->body);
        if ($result->warnings !== []) {
            $this->logger->info('Government RSS included skipped rows.', [
                'source' => $this->sourceName,
                'source_rows' => $result->sourceRows,
                'skipped_rows' => $result->skippedRows(),
                'warnings' => array_slice($result->warnings, 0, 20),
            ]);
        }
        return $result->announcements;
    }
}
