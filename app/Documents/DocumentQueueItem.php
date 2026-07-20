<?php

declare(strict_types=1);

namespace HalalPulse\Documents;

use DateTimeImmutable;

final readonly class DocumentQueueItem
{
    public function __construct(
        public int $documentId,
        public int $filingId,
        public string $exchange,
        public string $sourceUrl,
        public DateTimeImmutable $announcedAt,
        public ?string $storagePath = null,
    ) {
    }
}
