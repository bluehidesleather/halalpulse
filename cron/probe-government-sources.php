#!/usr/bin/env php
<?php

declare(strict_types=1);

use HalalPulse\Government\GovernmentHttpClientFactory;
use HalalPulse\Government\GovernmentSourceFactory;
use HalalPulse\Support\JsonLogger;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$config = require dirname(__DIR__) . '/app/bootstrap.php';
$allowed = ['PIB', 'SEBI', 'RBI', 'MCA', 'BUDGET'];
$requested = array_map('strtoupper', array_slice($argv, 1));
$requested = $requested === [] ? $allowed : array_values(array_unique($requested));
foreach ($requested as $source) {
    if (!in_array($source, $allowed, true)) {
        throw new InvalidArgumentException('Usage: php cron/probe-government-sources.php [PIB] [SEBI] [RBI] [MCA] [BUDGET]');
    }
}

$logger = new JsonLogger($config->requireString('app.log_path'));
$factory = new GovernmentSourceFactory($config, GovernmentHttpClientFactory::fromConfig($config), $logger);
$results = [];
$failed = false;
foreach ($requested as $source) {
    try {
        $announcements = $factory->create($source)->fetchLatest(null);
        $latest = null;
        foreach ($announcements as $announcement) {
            if ($latest === null || $announcement->publishedAt > $latest) {
                $latest = $announcement->publishedAt;
            }
        }
        $results[] = [
            'source' => $source,
            'status' => 'succeeded',
            'mapped_announcements' => count($announcements),
            'latest_published_at' => $latest?->format(DATE_ATOM),
        ];
    } catch (Throwable $exception) {
        $failed = true;
        $results[] = ['source' => $source, 'status' => 'failed', 'error_type' => $exception::class, 'message' => $exception->getMessage()];
    }
}

echo json_encode(['status' => $failed ? 'failed' : 'succeeded', 'sources' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
exit($failed ? 1 : 0);
