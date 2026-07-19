<?php

declare(strict_types=1);

namespace HalalPulse\Government;

use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Throwable;

final readonly class OfficialListingMapper
{
    /** @param list<string> $requiredMarkers @param list<string> $linkPathContains */
    public function __construct(
        private string $source,
        private string $baseUrl,
        private string $category,
        private array $requiredMarkers,
        private array $linkPathContains,
        private ?DateTimeImmutable $defaultPublicationDate = null,
    ) {
    }

    public function map(string $html, ?DateTimeImmutable $lastModified = null): GovernmentMappingResult
    {
        foreach ($this->requiredMarkers as $marker) {
            if ($marker === '' || stripos($html, $marker) === false) {
                throw new GovernmentSourceFormatException('Official listing contract marker is missing: ' . $marker);
            }
        }
        if (!class_exists(DOMDocument::class)) {
            throw new GovernmentSourceFormatException('The PHP DOM extension is required for official listing parsing.');
        }

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            libxml_clear_errors();
        } finally {
            libxml_use_internal_errors($previous);
        }
        if (!$loaded) {
            throw new GovernmentSourceFormatException('Official listing response is not parseable HTML.');
        }

        $xpath = new DOMXPath($document);
        $nodes = $xpath->query('//a[@href]');
        if ($nodes === false || $nodes->length === 0) {
            throw new GovernmentSourceFormatException('Official listing contains no links.');
        }

        $rows = 0;
        $warnings = [];
        $announcements = [];
        $seen = [];
        foreach ($nodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $href = trim($node->getAttribute('href'));
            $title = trim((string) preg_replace('/\s+/u', ' ', $node->textContent));
            if ($href === '' || mb_strlen($title) < 3 || !$this->pathMatches($href)) {
                continue;
            }
            $rows++;
            try {
                $url = $this->absoluteUrl($href);
                if (!GovernmentOfficialUrl::isAllowed($url, $this->source)) {
                    throw new GovernmentSourceFormatException('Item link is outside the source-specific official host allowlist.');
                }
                $date = $this->dateFromContext((string) ($node->parentNode?->textContent ?? ''))
                    ?? $lastModified
                    ?? $this->defaultPublicationDate;
                if ($date === null) {
                    throw new GovernmentSourceFormatException('No publication date or approved fallback date was available.');
                }
                $identity = hash('sha256', $url . "\n" . $title);
                if (isset($seen[$identity])) {
                    continue;
                }
                $seen[$identity] = true;
                $announcements[] = new GovernmentAnnouncement(
                    source: $this->source,
                    sourceId: strtolower($this->source) . ':' . $identity,
                    category: mb_substr($this->category, 0, 255),
                    title: mb_substr(html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8'), 0, 1000),
                    summary: '',
                    publishedAt: $date,
                    officialUrl: $url,
                    rawPayload: [
                        'title' => $title,
                        'url' => $url,
                        'publication_date' => $date->format(DATE_ATOM),
                        'date_basis' => $this->dateFromContext((string) ($node->parentNode?->textContent ?? '')) !== null
                            ? 'listing_context'
                            : ($lastModified !== null ? 'http_last_modified' : 'configured_default'),
                    ],
                );
            } catch (Throwable $exception) {
                $warnings[] = sprintf('Listing row %d skipped: %s', $rows, $exception->getMessage());
            }
        }

        if ($rows === 0 || $announcements === []) {
            throw new GovernmentSourceFormatException('Official listing contract produced no valid announcement rows.');
        }

        return new GovernmentMappingResult($announcements, $rows, $warnings);
    }

    private function pathMatches(string $href): bool
    {
        foreach ($this->linkPathContains as $needle) {
            if ($needle !== '' && stripos($href, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    private function absoluteUrl(string $href): string
    {
        if (str_starts_with($href, 'https://')) {
            return $href;
        }
        if (str_starts_with($href, '//')) {
            return 'https:' . $href;
        }
        $base = parse_url($this->baseUrl);
        if (!is_array($base) || ($base['host'] ?? '') === '') {
            throw new GovernmentSourceFormatException('Official listing base URL is invalid.');
        }
        $origin = 'https://' . $base['host'];
        if (str_starts_with($href, '/')) {
            return $origin . $href;
        }
        $path = (string) ($base['path'] ?? '/');
        $directory = rtrim(str_replace('\\', '/', dirname($path)), '/.');
        return $origin . ($directory === '' ? '' : '/' . ltrim($directory, '/')) . '/' . ltrim($href, '/');
    }

    private function dateFromContext(string $context): ?DateTimeImmutable
    {
        $context = trim((string) preg_replace('/\s+/u', ' ', $context));
        $patterns = [
            '/\b(\d{1,2}\s+(?:Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:tember)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)\s+\d{4})\b/i',
            '/\b((?:Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:tember)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)\s+\d{1,2},?\s+\d{4})\b/i',
            '/\b(\d{4}-\d{2}-\d{2})\b/',
            '/\b(\d{2}[\/-]\d{2}[\/-]\d{4})\b/',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $context, $match) !== 1) {
                continue;
            }
            try {
                return new DateTimeImmutable($match[1]);
            } catch (Throwable) {
                continue;
            }
        }
        return null;
    }
}
