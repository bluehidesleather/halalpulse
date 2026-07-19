<?php

declare(strict_types=1);

namespace HalalPulse\Ingestion;

use HalalPulse\Support\OfficialUrl;
use Throwable;

final class NseAnnouncementMapper
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
                $warnings[] = "NSE row {$index} is not an object.";
                continue;
            }

            try {
                $filings[] = $this->mapRow($row);
            } catch (Throwable $exception) {
                $warnings[] = "NSE row {$index} was skipped: {$exception->getMessage()}";
            }
        }

        if ($rows !== [] && $filings === []) {
            throw new SourceFormatException('NSE response contained rows but none matched the expected announcement contract.');
        }

        return new AnnouncementMappingResult($filings, count($rows), $warnings);
    }

    /** @return list<mixed> */
    private function rows(array $payload): array
    {
        if (array_is_list($payload)) {
            return $payload;
        }

        foreach (['data', 'announcements', 'records'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key]) && array_is_list($payload[$key])) {
                return $payload[$key];
            }
        }

        throw new SourceFormatException('NSE response envelope is not recognized.');
    }

    private function mapRow(array $row): Filing
    {
        $symbol = $this->required($row, ['symbol', 'sm_symbol'], 'symbol');
        $companyName = $this->required($row, ['sm_name', 'companyName', 'company_name'], 'company name');
        $category = $this->optional($row, ['desc', 'category', 'categoryName']) ?? 'Corporate Announcement';
        $subject = $this->optional($row, ['attchmntText', 'subject', 'headline', 'description']) ?? $category;
        $timestamp = $this->required(
            $row,
            ['an_dt', 'sort_date', 'broadCastDate', 'broadcastDate', 'dt'],
            'announcement timestamp',
        );
        $attachment = OfficialUrl::attachment(
            $this->optional($row, ['attchmntFile', 'attachment', 'attachmentUrl']),
            'https://nsearchives.nseindia.com/corporate/',
            ['nsearchives.nseindia.com', 'archives.nseindia.com', 'www.nseindia.com'],
        );

        return new Filing(
            exchange: 'NSE',
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
        $native = $this->optional($row, ['seq_id', 'sequenceId', 'broadcastId', 'id']);
        $id = $native === null
            ? hash('sha256', implode('|', [$symbol, $timestamp, $subject]))
            : preg_replace('/[^A-Za-z0-9._:-]/', '-', $native);
        $id = trim((string) $id, '-');

        return strlen($id) <= 187 ? 'nse:' . $id : 'nse:' . hash('sha256', $id);
    }

    /** @param list<string> $keys */
    private function required(array $row, array $keys, string $label): string
    {
        $value = $this->optional($row, $keys);
        if ($value === null) {
            throw new SourceFormatException("Missing NSE {$label}.");
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
