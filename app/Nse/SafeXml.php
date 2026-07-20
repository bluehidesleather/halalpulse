<?php

declare(strict_types=1);

namespace HalalPulse\Nse;

use DOMDocument;

final class SafeXml
{
    public static function load(string $xml, string $label): DOMDocument
    {
        if (!extension_loaded('dom')) {
            throw new NseSourceException('The PHP DOM extension is required for NSE XML ingestion.');
        }

        $trimmed = ltrim($xml);
        if ($trimmed === '' || stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            throw new NseSourceException("{$label} is empty or contains a prohibited XML declaration.");
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $document = new DOMDocument();

        try {
            $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_COMPACT);
            if (!$loaded || $document->documentElement === null) {
                throw new NseSourceException("{$label} is not well-formed XML.");
            }

            return $document;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
