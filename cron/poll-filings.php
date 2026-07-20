#!/usr/bin/env php
<?php

declare(strict_types=1);

use HalalPulse\Database;
use HalalPulse\Http\HttpClientFactory;
use HalalPulse\Ingestion\FilingPoller;
use HalalPulse\Ingestion\FilingSourceFactory;
use HalalPulse\Ingestion\FilingStore;
use HalalPulse\Ingestion\QuarterlyResultClassifier;
use HalalPulse\Support\JsonLogger;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$config = require dirname(__DIR__) . '/app/bootstrap.php';
$logger = new JsonLogger($config->requireString('app.log_path'));
$enabled = [];

foreach (['nse', 'bse'] as $exchange) {
    if ($config->get("sources.{$exchange}.enabled", false) === true) {
        $enabled[] = strtoupper($exchange);
    }
}

if ($enabled === []) {
    echo json_encode([
        'status' => 'not_configured',
        'message' => 'No exchange adapters are enabled. Run cron/probe-sources.php before activation.',
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    exit(0);
}

$http = HttpClientFactory::fromConfig($config);
$factory = new FilingSourceFactory($config, $http, $logger);
$poller = new FilingPoller(
    new FilingStore(Database::connect($config)),
    new QuarterlyResultClassifier(),
    $logger,
);
$results = [];
$failed = false;

foreach ($enabled as $exchange) {
    try {
        $results[] = $poller->poll($factory->create($exchange));
    } catch (Throwable $exception) {
        $failed = true;
        $results[] = [
            'exchange' => $exchange,
            'status' => 'failed',
            'message' => $exception->getMessage(),
        ];
    }
}

echo json_encode([
    'status' => $failed ? 'failed' : 'succeeded',
    'sources' => $results,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;

exit($failed ? 1 : 0);
