<?php

declare(strict_types=1);

namespace HalalPulse\Nse;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class IntegratedFeedItem
{
    /** @param array<string, string> $rawPayload */
    public function __construct(
        public string $companyName,
        public string $sourceUrl,
        public string $description,
        public string $filingType,
        public string $revisionNote,
        public DateTimeImmutable $publishedAt,
        public array $rawPayload,
    ) {
        if (trim($this->companyName) === '') {
            throw new InvalidArgumentException('NSE RSS company name cannot be empty.');
        }

        if (!NseIntegratedUrl::isAllowedXbrl($this->sourceUrl)) {
            throw new InvalidArgumentException('NSE RSS item does not link to an official Integrated Filing XBRL.');
        }

        if (!in_array($this->filingType, ['original', 'revision', 'other'], true)) {
            throw new InvalidArgumentException('NSE RSS filing type is invalid.');
        }
    }

    public function sourceFilename(): string
    {
        return NseIntegratedUrl::filename($this->sourceUrl);
    }

    public function sourceId(): string
    {
        $filename = pathinfo($this->sourceFilename(), PATHINFO_FILENAME);
        $value = 'nse-if:' . $filename;

        return strlen($value) <= 191 ? $value : 'nse-if:' . hash('sha256', $filename);
    }

    public function itemHash(): string
    {
        $json = json_encode(
            $this->rawPayload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );

        return hash('sha256', $json);
    }
}
