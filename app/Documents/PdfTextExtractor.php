<?php

declare(strict_types=1);

namespace HalalPulse\Documents;

interface PdfTextExtractor
{
    public function available(): bool;

    public function extract(string $absolutePdfPath): string;
}
