<?php

declare(strict_types=1);

namespace HalalPulse\Documents;

use HalalPulse\Http\HttpClient;
use RuntimeException;

final class OfficialDocumentDownloader
{
    public function __construct(
        private readonly HttpClient $http,
        private readonly string $storageRoot,
    ) {
    }

    public function download(DocumentQueueItem $item): DownloadedDocument
    {
        $response = $this->http->get($item->sourceUrl, [
            'Accept' => 'application/pdf, application/octet-stream;q=0.8',
            'Accept-Language' => 'en-IN,en;q=0.9',
            'Referer' => $item->exchange === 'NSE'
                ? 'https://www.nseindia.com/companies-listing/corporate-filings-announcements'
                : 'https://www.bseindia.com/corporates/ann.html',
        ]);

        if (!str_starts_with($response->body, '%PDF-')) {
            throw new UnsupportedDocumentException('Official attachment is not a PDF document.');
        }

        $size = strlen($response->body);
        if ($size < 100) {
            throw new UnsupportedDocumentException('Official PDF attachment is unexpectedly small.');
        }

        $sha256 = hash('sha256', $response->body);
        $year = $item->announcedAt->format('Y');
        $month = $item->announcedAt->format('m');
        $relativeDirectory = strtolower($item->exchange) . '/' . $year . '/' . $month;
        $relativePath = $relativeDirectory . '/filing-' . $item->filingId . '-' . substr($sha256, 0, 16) . '.pdf';
        $directory = rtrim($this->storageRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $relativeDirectory;
        $absolutePath = rtrim($this->storageRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $relativePath;

        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create the private document directory.');
        }

        if (is_file($absolutePath)) {
            $existingHash = hash_file('sha256', $absolutePath);
            if (is_string($existingHash) && hash_equals($sha256, $existingHash)) {
                return new DownloadedDocument($relativePath, $absolutePath, 'application/pdf', $size, $sha256);
            }
        }

        $temporaryPath = $absolutePath . '.tmp-' . bin2hex(random_bytes(6));

        try {
            $written = file_put_contents($temporaryPath, $response->body, LOCK_EX);
            if ($written !== $size) {
                throw new RuntimeException('Unable to write the complete PDF attachment.');
            }

            chmod($temporaryPath, 0660);

            if (!rename($temporaryPath, $absolutePath)) {
                throw new RuntimeException('Unable to finalize the private PDF attachment.');
            }
        } finally {
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }

        return new DownloadedDocument($relativePath, $absolutePath, 'application/pdf', $size, $sha256);
    }
}
