<?php

declare(strict_types=1);

namespace HalalPulse\Government;

use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Throwable;

final readonly class RssAnnouncementMapper
{
    /** @param list<string> $requiredMarkers */
    public function __construct(
        private string $source,
        private array $requiredMarkers = [],
    ) {
    }

    public function map(string $xml): GovernmentMappingResult
    {
        $this->assertContractMarkers($xml);
        if (!class_exists(DOMDocument::class)) {
            throw new GovernmentSourceFormatException('The PHP DOM extension is required for official RSS parsing.');
        }

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_NOBLANKS);
            $errors = libxml_get_errors();
            libxml_clear_errors();
        } finally {
            libxml_use_internal_errors($previous);
        }
        if (!$loaded || $errors !== []) {
            throw new GovernmentSourceFormatException('Official RSS response is not well-formed XML.');
        }

        $xpath = new DOMXPath($document);
        $nodes = $xpath->query('//*[local-name()="item" or local-name()="entry"]');
        if ($nodes === false || $nodes->length === 0) {
            throw new GovernmentSourceFormatException('Official RSS response contains no item or entry rows.');
        }

        $announcements = [];
        $warnings = [];
        foreach ($nodes as $index => $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            try {
                $title = $this->childText($node, ['title']);
                $url = $this->link($node);
                $published = $this->date($this->childText($node, ['pubDate', 'published', 'updated', 'dc:date', 'date']));
                $guid = $this->childText($node, ['guid', 'id'], false);
                $category = $this->childText($node, ['category'], false);
                $summary = $this->plainText($this->childText($node, ['description', 'summary', 'content'], false));
                if (!GovernmentOfficialUrl::isAllowed($url, $this->source)) {
                    throw new GovernmentSourceFormatException('Item link is outside the source-specific official host allowlist.');
                }
                $identity = $guid !== '' ? $guid : $url;
                $sourceId = strtolower($this->source) . ':' . hash('sha256', $identity);
                $raw = [
                    'title' => $title,
                    'url' => $url,
                    'guid' => $guid,
                    'published' => $published->format(DATE_ATOM),
                    'category' => $category,
                    'summary' => $summary,
                ];
                $announcements[] = new GovernmentAnnouncement(
                    source: $this->source,
                    sourceId: $sourceId,
                    category: mb_substr($category, 0, 255),
                    title: mb_substr($title, 0, 1000),
                    summary: mb_substr($summary, 0, 4000),
                    publishedAt: $published,
                    officialUrl: $url,
                    rawPayload: $raw,
                );
            } catch (Throwable $exception) {
                $warnings[] = sprintf('Row %d skipped: %s', $index + 1, $exception->getMessage());
            }
        }

        if ($announcements === []) {
            throw new GovernmentSourceFormatException('Official RSS contract produced no valid announcement rows.');
        }

        return new GovernmentMappingResult($announcements, $nodes->length, $warnings);
    }

    private function assertContractMarkers(string $xml): void
    {
        foreach ($this->requiredMarkers as $marker) {
            if ($marker === '' || stripos($xml, $marker) === false) {
                throw new GovernmentSourceFormatException('Official RSS contract marker is missing: ' . $marker);
            }
        }
    }

    /** @param list<string> $names */
    private function childText(DOMNode $node, array $names, bool $required = true): string
    {
        foreach ($node->childNodes as $child) {
            $local = strtolower((string) ($child->localName ?? $child->nodeName));
            foreach ($names as $name) {
                $expected = strtolower(str_contains($name, ':') ? substr($name, (int) strrpos($name, ':') + 1) : $name);
                if ($local === $expected) {
                    $value = trim($child->textContent);
                    if ($value !== '') {
                        return $value;
                    }
                }
            }
        }
        if ($required) {
            throw new GovernmentSourceFormatException('Required RSS field is missing: ' . implode('/', $names));
        }
        return '';
    }

    private function link(DOMElement $node): string
    {
        foreach ($node->childNodes as $child) {
            if (strtolower((string) ($child->localName ?? '')) !== 'link') {
                continue;
            }
            if ($child instanceof DOMElement && $child->hasAttribute('href')) {
                $href = trim($child->getAttribute('href'));
                if ($href !== '') {
                    return $href;
                }
            }
            $value = trim($child->textContent);
            if ($value !== '') {
                return $value;
            }
        }
        throw new GovernmentSourceFormatException('Required RSS link is missing.');
    }

    private function date(string $value): DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            throw new GovernmentSourceFormatException('RSS publication date is invalid.');
        }
    }

    private function plainText(string $value): string
    {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }
}
