<?php

declare(strict_types=1);

namespace HalalPulse\Government;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class GovernmentAnnouncement
{
    private const SOURCES = ['PIB', 'SEBI', 'RBI', 'MCA', 'BUDGET'];

    /** @param array<string, mixed> $rawPayload */
    public function __construct(
        public string $source,
        public string $sourceId,
        public string $category,
        public string $title,
        public string $summary,
        public DateTimeImmutable $publishedAt,
        public string $officialUrl,
        public array $rawPayload,
    ) {
        if (!in_array($this->source, self::SOURCES, true)) {
            throw new InvalidArgumentException('Government announcement source is invalid.');
        }
        if ($this->sourceId === '' || strlen($this->sourceId) > 191) {
            throw new InvalidArgumentException('Government announcement source ID is invalid.');
        }
        if (mb_strlen($this->title) < 3 || mb_strlen($this->title) > 1000) {
            throw new InvalidArgumentException('Government announcement title is invalid.');
        }
        if (!GovernmentOfficialUrl::isAllowed($this->officialUrl, $this->source)) {
            throw new InvalidArgumentException('Government announcement URL is outside its official-source allowlist.');
        }
    }

    public function payloadHash(): string
    {
        return hash('sha256', json_encode(
            $this->rawPayload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
    }
}
