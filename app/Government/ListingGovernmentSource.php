<?php

declare(strict_types=1);

namespace HalalPulse\Government;

use DateTimeImmutable;
use HalalPulse\Http\HttpClient;
use HalalPulse\Support\JsonLogger;
use Throwable;

final readonly class ListingGovernmentSource implements GovernmentSource
{
    public function __construct(
        private string $sourceName,
        private string $endpoint,
        private HttpClient $http,
        private OfficialListingMapper $mapper,
        private JsonLogger $logger,
    ) {
    }

    public function source(): string
    {
        return $this->sourceName;
    }

    public function fetchLatest(?DateTimeImmutable $checkpoint): array
    {
        $response = $this->http->get($this->endpoint, ['Accept' => 'text/html,application/xhtml+xml']);
        $lastModified = null;
        $header = $response->header('last-modified');
        if (is_string($header) && $header !== '') {
            try {
                $lastModified = new DateTimeImmutable($header);
            } catch (Throwable) {
                $lastModified = null;
            }
        }
        $result = $this->mapper->map($response->body, $lastModified);
        if ($result->warnings !== []) {
            $this->logger->info('Government listing included skipped rows.', [
                'source' => $this->sourceName,
                'source_rows' => $result->sourceRows,
                'skipped_rows' => $result->skippedRows(),
                'warnings' => array_slice($result->warnings, 0, 20),
            ]);
        }
        return $result->announcements;
    }
}
