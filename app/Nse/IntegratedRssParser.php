<?php

declare(strict_types=1);

namespace HalalPulse\Nse;

use DateTimeImmutable;
use DateTimeZone;
use DOMElement;
use DOMXPath;
use Throwable;

final class IntegratedRssParser
{
    public function parse(string $xml): IntegratedFeed
    {
        $document = SafeXml::load($xml, 'NSE Integrated Filing RSS feed');
        if (strtolower((string) $document->documentElement?->localName) !== 'rss') {
            throw new NseSourceException('NSE Integrated Filing feed root must be RSS.');
        }

        $xpath = new DOMXPath($document);
        $title = $this->text($xpath, '/*[local-name()="rss"]/*[local-name()="channel"]/*[local-name()="title"]');
        if ($title === null || !str_contains(strtoupper($title), 'INTEGRATED_FILING_FINANCIALS')) {
            throw new NseSourceException('NSE RSS channel identity does not match Integrated Filing Financials.');
        }

        $lastBuildRaw = $this->text($xpath, '/*[local-name()="rss"]/*[local-name()="channel"]/*[local-name()="lastBuildDate"]');
        $lastBuildAt = $this->parseDate($lastBuildRaw, true, 'lastBuildDate');
        $ttlRaw = $this->text($xpath, '/*[local-name()="rss"]/*[local-name()="channel"]/*[local-name()="ttl"]');
        $ttl = ctype_digit((string) $ttlRaw) ? (int) $ttlRaw : 5;
        $ttl = max(5, min(60, $ttl));
        $nodes = $xpath->query('/*[local-name()="rss"]/*[local-name()="channel"]/*[local-name()="item"]');
        if ($nodes === false) {
            throw new NseSourceException('Unable to read NSE RSS items.');
        }

        $items = [];
        $warnings = [];
        foreach ($nodes as $index => $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }

            try {
                $items[] = $this->parseItem($node);
            } catch (Throwable $exception) {
                $warnings[] = sprintf('RSS item %d skipped: %s', $index, $exception->getMessage());
            }
        }

        if ($nodes->length > 0 && $items === []) {
            throw new NseSourceException('NSE RSS contained items but none matched the official contract.');
        }

        return new IntegratedFeed(
            title: $title,
            lastBuildAt: $lastBuildAt,
            ttlMinutes: $ttl,
            sourceRows: $nodes->length,
            items: $items,
            warnings: $warnings,
        );
    }

    private function parseItem(DOMElement $node): IntegratedFeedItem
    {
        $values = [];
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $values[strtolower($child->localName)] = trim($child->textContent);
            }
        }

        $company = $values['title'] ?? '';
        $url = $values['link'] ?? '';
        $description = $values['description'] ?? '';
        $publishedAt = $this->parseDate($values['pubdate'] ?? null, false, 'item pubDate');
        $parts = array_map('trim', explode('|', $description));
        $typeValue = strtolower((string) ($parts[1] ?? ''));
        $filingType = match ($typeValue) {
            'original' => 'original',
            'revision', 'revised' => 'revision',
            default => 'other',
        };
        $revisionNote = trim(implode('|', array_slice($parts, 2)));

        return new IntegratedFeedItem(
            companyName: $company,
            sourceUrl: $url,
            description: $description,
            filingType: $filingType,
            revisionNote: $revisionNote,
            publishedAt: $publishedAt,
            rawPayload: [
                'title' => $company,
                'link' => $url,
                'description' => $description,
                'pubDate' => $values['pubdate'] ?? '',
            ],
        );
    }

    private function text(DOMXPath $xpath, string $query): ?string
    {
        $nodes = $xpath->query($query);
        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        $value = trim((string) $nodes->item(0)?->textContent);

        return $value === '' ? null : $value;
    }

    private function parseDate(?string $value, bool $hasTimezone, string $label): DateTimeImmutable
    {
        $value = trim((string) $value);
        if ($value === '') {
            throw new NseSourceException("NSE RSS {$label} is missing.");
        }

        $timezone = new DateTimeZone('Asia/Kolkata');
        $formats = $hasTimezone
            ? [DATE_RFC2822, 'D, d M Y H:i:s O']
            : ['!d-M-Y H:i:s', '!d M Y H:i:s'];

        foreach ($formats as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value, $timezone);
            $errors = DateTimeImmutable::getLastErrors();
            if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $date->setTimezone($timezone);
            }
        }

        throw new NseSourceException("NSE RSS {$label} is invalid.");
    }
}
