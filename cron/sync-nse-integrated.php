#!/usr/bin/env php
<?php

declare(strict_types=1);

use HalalPulse\Database;
use HalalPulse\Http\HttpClientFactory;
use HalalPulse\Nse\IntegratedRssParser;
use HalalPulse\Nse\IntegratedXbrlParser;
use HalalPulse\Nse\NseActivityExclusionService;
use HalalPulse\Nse\NseIntegratedStore;
use HalalPulse\Nse\NseIntegratedSyncService;
use HalalPulse\Nse\NseSyncRequestRepository;
use HalalPulse\Nse\XmlArchive;
use HalalPulse\Support\JsonLogger;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$config = require dirname(__DIR__) . '/app/bootstrap.php';
if ($config->get('sources.nse_integrated_rss.enabled', false) !== true) {
    echo json_encode([
        'status' => 'not_configured',
        'message' => 'NSE Integrated Filing RSS is disabled.',
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    exit(0);
}

$pdo = Database::connect($config);
$requests = new NseSyncRequestRepository($pdo);
$manualRequest = $requests->reservePending();
$trigger = $manualRequest === null ? 'scheduled' : 'manual';
$requestId = $manualRequest['id'] ?? null;

try {
    $result = (new NseIntegratedSyncService(
        http: HttpClientFactory::fromConfig($config),
        rssParser: new IntegratedRssParser(),
        xbrlParser: new IntegratedXbrlParser(),
        archive: new XmlArchive($config->requireString('sources.nse_integrated_rss.storage_path')),
        store: new NseIntegratedStore($pdo),
        activityExclusions: new NseActivityExclusionService($pdo),
        logger: new JsonLogger($config->requireString('app.log_path')),
        feedUrl: $config->requireString('sources.nse_integrated_rss.endpoint'),
        batchSize: (int) $config->get('sources.nse_integrated_rss.batch_size', 20),
    ))->sync($trigger, $requestId);

    if ($requestId !== null) {
        if ($result['status'] === 'skipped') {
            $requests->returnToQueue($requestId);
        } else {
            $requests->complete($requestId, $result);
        }
    }

    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
    exit($result['status'] === 'partial' ? 1 : 0);
} catch (Throwable $exception) {
    if ($requestId !== null) {
        $requests->fail($requestId, $exception->getMessage());
    }

    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
