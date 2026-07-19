#!/usr/bin/env php
<?php

declare(strict_types=1);

use HalalPulse\Database;
use HalalPulse\Government\GovernmentAnnouncementStore;
use HalalPulse\Government\GovernmentHttpClientFactory;
use HalalPulse\Government\GovernmentPoller;
use HalalPulse\Government\GovernmentSectorClassifier;
use HalalPulse\Government\GovernmentSourceFactory;
use HalalPulse\Support\JsonLogger;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$config = require dirname(__DIR__) . '/app/bootstrap.php';
$enabled = [];
foreach (['pib', 'sebi', 'rbi', 'mca', 'budget'] as $source) {
    if ($config->get("government_sources.{$source}.enabled", false) === true) {
        $enabled[] = strtoupper($source);
    }
}
if ($enabled === []) {
    echo json_encode(['status' => 'not_configured', 'message' => 'No government adapters are enabled. Run cron/probe-government-sources.php before activation.'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    exit(0);
}

$logger = new JsonLogger($config->requireString('app.log_path'));
$factory = new GovernmentSourceFactory($config, GovernmentHttpClientFactory::fromConfig($config), $logger);
$poller = new GovernmentPoller(
    new GovernmentAnnouncementStore(Database::connect($config)),
    new GovernmentSectorClassifier(),
    $logger,
    (int) $config->get('government_polling.interval_seconds', 3600),
);
$results = [];
$failed = false;
foreach ($enabled as $source) {
    try {
        $results[] = $poller->poll($factory->create($source));
    } catch (Throwable $exception) {
        $failed = true;
        $results[] = ['source' => $source, 'status' => 'failed', 'message' => $exception->getMessage()];
    }
}
echo json_encode(['status' => $failed ? 'failed' : 'succeeded', 'sources' => $results], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
exit($failed ? 1 : 0);
