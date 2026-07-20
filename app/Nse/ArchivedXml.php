<?php

declare(strict_types=1);

namespace HalalPulse\Nse;

final readonly class ArchivedXml
{
    public function __construct(
        public string $relativePath,
        public string $absolutePath,
        public string $sha256,
        public int $sizeBytes,
    ) {
    }
}
