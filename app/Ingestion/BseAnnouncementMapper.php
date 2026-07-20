<?php

declare(strict_types=1);

namespace HalalPulse\Ingestion;

use HalalPulse\Support\OfficialUrl;
use JsonException;
use Throwable;

final class BseAnnouncementMapper
{
    public function __construct(private readonly ExchangeDateParser $dateParser = new ExchangeDateParser())
    {
    }

    public function map(array $payload): AnnouncementMappingResult
    {
        $rows = $this->rows($payload);
        $filings = [];
        $warnings = [];

        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                $warnings[] = "BSE row {$index} is not an object.";
                continue;
            }

            try {
                $filings[] = $this->mapRow($row);
            } catch (Throwable $exception) {
                $warnings[] = "BSE row {$index} was skipped: {$exception->getMessage()}";
            }
        }

        if ($rows !== [] && $filings === []) {
            throw new SourceFormatException('BSE response contained rows but none matched the expected announcement contract.');
        }

        return new AnnouncementMappingResult($filings, count($rows), $warnings);
    }

    /** @return list<mixed> */
    private function rows(array $payload): array
    {
        if (array_is_list($payload)) {
            return $payload;
        }

        foreach (['Table', 'table', 'data', 'announcements'] as $key) {
            if (!array_key_exists($key, $payload)) {
                continue;
            }

            $candidate = $payload[$key];
            if (is_string($candidate)) {
                try {
                    $candidate = json_decode($candidate, true, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    throw new SourceFormatException('BSE Table field is not valid JSON.');
                }
            }

            if (is_array($candidate) && array_is_list($candidate)) {
                return $candidate;
            }
        }

        throw new SourceFormatException('BSE response envelope is not recognized.');
    }

    private function mapRow(array $row): Filing
    {
        $symbol = $this->required($row, ['SCRIP_CD', 'SCRIPCODE', 'scripCode', 'symbol'], 'scrip code');
        $companyName = $this->required($row, ['SLONGNAME', 'LONG_NAME', 'companyName', 'company_name'], 'company name');
        $category = $this->optional($row, ['CATEGORYNAME', 'CATEGORY', 'category']) ?? 'Corporate Announcement';
        $subject = $this->required($row, ['NEWSSUB', 'HEADLINE', 'subject', 'description'], 'subject');
        $timestamp = $this->required($row, ['NEWS_DT', 'NEWS_DATE', 'announcementDate', 'dt'], 'announcement timestamp');
        $attachment = OfficialUrl::attachment(
            $this->optional($row, ['NSURL', 'ATTACHMENTNAME', 'attachmentUrl', 'attachment']),
            'https://www.bseindia.com/xml-data/corpfiling/AttachLive/',
            ['www.bseindia.com', 'api.bseindia.com'],
        );

        return new Filing(
            exchange: 'BSE',
            sourceId: $this->sourceId($row, $symbol, $timestamp, $subject),
            symbol: strtoupper($symbol),
            companyName: $companyName,
            category: $category,
            subject: $subject,
            announcedAt: $this->dateParser->parse($timestamp),
            attachmentUrl: $attachment,
            rawPayload: $row,
        );
    }

    private function sourceId(array $row, string $symbol, string $timestamp, string $subject): string
    {
        $native = $this->optional($row, ['NEWSID', 'NEWS_ID', 'newsId', 'id']);
        $id = $native === null
            ? hash('sha256', implode('|', [$symbol, $timestamp, $subject]))
            : preg_replace('/[^A-Za-z0-9._:-]/', '-', $native);
        $id = trim((string) $id, '-');

        return strlen($id) <= 187 ? 'bse:' . $id : 'bse:' . hash('sha256', $id);
    }

    /** @param list<string> $keys */
    private function required(array $row, array $keys, string $label): string
    {
        $value = $this->optional($row, $keys);
        if ($value === null) {
            throw new SourceFormatException("Missing BSE {$label}.");
        }

        return $value;
    }

    /** @param list<string> $keys */
    private function optional(array $row, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row) && is_scalar($row[$key])) {
                $value = trim((string) $row[$key]);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }
}
