<?php

declare(strict_types=1);

namespace HalalPulse\Government;

use DateTimeImmutable;
use HalalPulse\Config;
use HalalPulse\Http\HttpClient;
use HalalPulse\Support\JsonLogger;
use InvalidArgumentException;
use Throwable;

final readonly class GovernmentSourceFactory
{
    public function __construct(
        private Config $config,
        private HttpClient $http,
        private JsonLogger $logger,
    ) {
    }

    public function create(string $source): GovernmentSource
    {
        $source = strtoupper($source);
        if (!in_array($source, ['PIB', 'SEBI', 'RBI', 'MCA', 'BUDGET'], true)) {
            throw new InvalidArgumentException('Unsupported government source.');
        }
        $key = strtolower($source);
        $settings = $this->config->get('government_sources.' . $key, []);
        if (!is_array($settings)) {
            throw new InvalidArgumentException("government_sources.{$key} must be an array.");
        }
        $format = strtolower((string) ($settings['format'] ?? ''));
        $endpoint = (string) ($settings['endpoint'] ?? '');
        $markers = $this->stringList($settings['required_markers'] ?? []);
        if ($endpoint === '') {
            throw new InvalidArgumentException("Government source {$source} has no endpoint.");
        }

        if ($format === 'rss') {
            return new RssGovernmentSource(
                $source,
                $endpoint,
                $this->http,
                new RssAnnouncementMapper($source, $markers),
                $this->logger,
            );
        }
        if ($format !== 'html') {
            throw new InvalidArgumentException("Government source {$source} format must be rss or html.");
        }

        $defaultDate = null;
        $defaultDateValue = trim((string) ($settings['default_publication_date'] ?? ''));
        if ($defaultDateValue !== '') {
            try {
                $defaultDate = new DateTimeImmutable($defaultDateValue);
            } catch (Throwable) {
                throw new InvalidArgumentException("Government source {$source} default publication date is invalid.");
            }
        }
        return new ListingGovernmentSource(
            $source,
            $endpoint,
            $this->http,
            new OfficialListingMapper(
                source: $source,
                baseUrl: (string) ($settings['official_page'] ?? $endpoint),
                category: (string) ($settings['category'] ?? 'Official announcement'),
                requiredMarkers: $markers,
                linkPathContains: $this->stringList($settings['link_path_contains'] ?? []),
                defaultPublicationDate: $defaultDate,
            ),
            $this->logger,
        );
    }

    /** @return list<string> */
    private function stringList(mixed $values): array
    {
        if (!is_array($values)) {
            throw new InvalidArgumentException('Government source contract list must be an array.');
        }
        return array_values(array_filter($values, static fn (mixed $value): bool => is_string($value) && trim($value) !== ''));
    }
}
