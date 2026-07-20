<?php

declare(strict_types=1);

namespace HalalPulse\Nse;

use DateTimeImmutable;
use RuntimeException;

final class XmlArchive
{
    private readonly string $basePath;

    public function __construct(string $basePath)
    {
        $resolved = trim($basePath) === '' ? false : realpath($basePath);
        if (!is_string($resolved) || !is_dir($resolved) || !is_writable($resolved)) {
            throw new RuntimeException('NSE XML archive must be an existing writable directory.');
        }

        $webRoot = defined('HALALPULSE_ROOT') ? realpath(HALALPULSE_ROOT . '/public_html') : false;
        if (is_string($webRoot)
            && ($resolved === $webRoot || str_starts_with($resolved, $webRoot . DIRECTORY_SEPARATOR))) {
            throw new RuntimeException('NSE XML archive must remain outside public_html.');
        }

        $this->basePath = $resolved;
    }

    public function storeFeed(string $xml, DateTimeImmutable $lastBuildAt): ArchivedXml
    {
        $sha256 = hash('sha256', $xml);
        $relative = sprintf(
            'feeds/%s/%s.xml',
            $lastBuildAt->format('Y/m'),
            $sha256,
        );

        return $this->store($relative, $xml, $sha256);
    }

    public function storeXbrl(string $xml, IntegratedFeedItem $item): ArchivedXml
    {
        $sha256 = hash('sha256', $xml);
        $relative = sprintf(
            'filings/%s/%s-%s',
            $item->publishedAt->format('Y/m'),
            substr($sha256, 0, 16),
            $item->sourceFilename(),
        );

        return $this->store($relative, $xml, $sha256);
    }

    private function store(string $relative, string $xml, string $sha256): ArchivedXml
    {
        $relative = str_replace('\\', '/', $relative);
        if (str_contains($relative, '..') || str_starts_with($relative, '/')) {
            throw new RuntimeException('Refusing an unsafe NSE XML archive path.');
        }

        $absolute = rtrim($this->basePath, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $directory = dirname($absolute);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create the private NSE XML archive directory.');
        }

        if (is_file($absolute)) {
            $existing = hash_file('sha256', $absolute);
            if (!is_string($existing) || !hash_equals($sha256, $existing)) {
                throw new RuntimeException('An NSE XML archive path already exists with different content.');
            }

            return new ArchivedXml($relative, $absolute, $sha256, strlen($xml));
        }

        $temporary = tempnam($directory, '.halalpulse-xml-');
        if (!is_string($temporary)) {
            throw new RuntimeException('Unable to create a temporary NSE XML archive file.');
        }

        try {
            $written = file_put_contents($temporary, $xml, LOCK_EX);
            if ($written !== strlen($xml)) {
                throw new RuntimeException('NSE XML archive write was incomplete.');
            }

            chmod($temporary, 0600);
            if (!rename($temporary, $absolute)) {
                throw new RuntimeException('Unable to finalize the NSE XML archive file.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }

        return new ArchivedXml($relative, $absolute, $sha256, strlen($xml));
    }
}
