#!/usr/bin/env php
<?php

declare(strict_types=1);

use HalalPulse\Http\HttpClientFactory;
use HalalPulse\Ingestion\FilingSourceFactory;
use HalalPulse\Support\JsonLogger;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$config = require dirname(__DIR__) . '/app/bootstrap.php';
$requested = array_map('strtoupper', array_slice($argv, 1));
$requested = $requested === [] ? ['NSE', 'BSE'] : array_values(array_unique($requested));

foreach ($requested as $exchange) {
    if (!in_array($exchange, ['NSE', 'BSE'], true)) {
        throw new InvalidArgumentException('Usage: php cron/probe-sources.php [NSE] [BSE]');
    }
}

$logger = new JsonLogger($config->requireString('app.log_path'));
$factory = new FilingSourceFactory($config, HttpClientFactory::fromConfig($config), $logger);
$results = [];
$failed = false;

foreach ($requested as $exchange) {
    try {
        $filings = $factory->create($exchange)->fetchLatest(null);
        $latest = null;

        foreach ($filings as $filing) {
            if ($latest === null || $filing->announcedAt > $latest) {
                $latest = $filing->announcedAt;
            }
        }

        $results[] = [
            'exchange' => $exchange,
            'status' => 'succeeded',
            'mapped_filings' => count($filings),
            'latest_announcement_at' => $latest?->format(DATE_ATOM),
        ];
    } catch (Throwable $exception) {
        $failed = true;
        $results[] = [
            'exchange' => $exchange,
            'status' => 'failed',
            'error_type' => $exception::class,
            'message' => $exception->getMessage(),
        ];
    }
}

echo json_encode([
    'status' => $failed ? 'failed' : 'succeeded',
    'sources' => $results,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;

exit($failed ? 1 : 0);
