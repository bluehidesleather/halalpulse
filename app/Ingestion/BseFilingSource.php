<?php

declare(strict_types=1);

namespace HalalPulse\Ingestion;

use DateTimeImmutable;
use DateTimeZone;
use HalalPulse\Http\HttpClient;
use HalalPulse\Support\JsonLogger;
use JsonException;

final class BseFilingSource implements FilingSource
{
    public function __construct(
        private readonly HttpClient $http,
        private readonly BseAnnouncementMapper $mapper,
        private readonly JsonLogger $logger,
        private readonly string $endpoint,
        private readonly string $officialPage,
        private readonly int $lookbackDays = 2,
    ) {
    }

    public function exchange(): string
    {
        return 'BSE';
    }

    public function fetchLatest(?DateTimeImmutable $checkpoint): array
    {
        $response = $this->http->get($this->requestUrl($checkpoint), [
            'Accept' => 'application/json, text/plain, */*',
            'Accept-Language' => 'en-IN,en;q=0.9',
            'Referer' => $this->officialPage,
        ]);

        try {
            $payload = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new SourceFormatException('BSE response is not valid JSON.');
        }

        if (!is_array($payload)) {
            throw new SourceFormatException('BSE JSON response is not an array or object.');
        }

        $result = $this->mapper->map($payload);
        $this->logWarnings($result);

        return $result->filings;
    }

    private function requestUrl(?DateTimeImmutable $checkpoint): string
    {
        $timezone = new DateTimeZone('Asia/Kolkata');
        $today = new DateTimeImmutable('today', $timezone);
        $earliest = $today->modify('-' . max(0, $this->lookbackDays) . ' days');
        $from = $checkpoint?->setTimezone($timezone)->setTime(0, 0) ?? $earliest;

        if ($from < $earliest) {
            $from = $earliest;
        }

        $query = http_build_query([
            'pageno' => 1,
            'strCat' => -1,
            'strPrevDate' => $from->format('Ymd'),
            'strScrip' => '',
            'strSearch' => 'P',
            'strToDate' => $today->format('Ymd'),
            'strType' => 'C',
            'subcategory' => -1,
        ], '', '&', PHP_QUERY_RFC3986);

        return rtrim($this->endpoint, '?') . '?' . $query;
    }

    private function logWarnings(AnnouncementMappingResult $result): void
    {
        if ($result->warnings === []) {
            return;
        }

        $this->logger->info('BSE response included skipped rows.', [
            'source_rows' => $result->sourceRows,
            'skipped_rows' => $result->skippedRows(),
            'warnings' => $result->warnings,
        ]);
    }
}
