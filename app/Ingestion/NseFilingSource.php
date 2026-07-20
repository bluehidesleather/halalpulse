<?php

declare(strict_types=1);

namespace HalalPulse\Ingestion;

use DateTimeImmutable;
use HalalPulse\Http\HttpClient;
use HalalPulse\Support\JsonLogger;
use JsonException;

final class NseFilingSource implements FilingSource
{
    public function __construct(
        private readonly HttpClient $http,
        private readonly NseAnnouncementMapper $mapper,
        private readonly JsonLogger $logger,
        private readonly string $endpoint,
        private readonly string $officialPage,
    ) {
    }

    public function exchange(): string
    {
        return 'NSE';
    }

    public function fetchLatest(?DateTimeImmutable $checkpoint): array
    {
        $response = $this->http->get($this->endpoint, [
            'Accept' => 'application/json, text/plain, */*',
            'Accept-Language' => 'en-IN,en;q=0.9',
            'Referer' => $this->officialPage,
        ]);

        try {
            $payload = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new SourceFormatException('NSE response is not valid JSON.');
        }

        if (!is_array($payload)) {
            throw new SourceFormatException('NSE JSON response is not an array or object.');
        }

        $result = $this->mapper->map($payload);
        $this->logWarnings($result);

        return $result->filings;
    }

    private function logWarnings(AnnouncementMappingResult $result): void
    {
        if ($result->warnings === []) {
            return;
        }

        $this->logger->info('NSE response included skipped rows.', [
            'source_rows' => $result->sourceRows,
            'skipped_rows' => $result->skippedRows(),
            'warnings' => $result->warnings,
        ]);
    }
}
