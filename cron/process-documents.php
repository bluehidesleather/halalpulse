#!/usr/bin/env php
<?php

declare(strict_types=1);

use HalalPulse\Database;
use HalalPulse\Documents\DocumentPipeline;
use HalalPulse\Documents\DocumentStore;
use HalalPulse\Documents\MetricCandidateExtractor;
use HalalPulse\Documents\OfficialDocumentDownloader;
use HalalPulse\Documents\PdftotextExtractor;
use HalalPulse\Http\CurlHttpClient;
use HalalPulse\Support\JsonLogger;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$config = require dirname(__DIR__) . '/app/bootstrap.php';
$batchSize = (int) $config->get('documents.batch_size', 3);

foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--limit=(\d+)$/', (string) $argument, $matches) === 1) {
        $batchSize = (int) $matches[1];
    }
}

$batchSize = max(1, min(10, $batchSize));
$allowedHosts = $config->get('documents.allowed_hosts', []);

if (!is_array($allowedHosts)) {
    throw new RuntimeException('documents.allowed_hosts must be an array.');
}

$storageRoot = $config->requireString('documents.storage_path');
$logger = new JsonLogger($config->requireString('app.log_path'));
$http = new CurlHttpClient(
    allowedHosts: array_values(array_filter($allowedHosts, 'is_string')),
    timeoutSeconds: (int) $config->get('documents.request_timeout_seconds', 30),
    userAgent: (string) $config->get('polling.user_agent', 'HalalPulse/0.9'),
    maxResponseBytes: (int) $config->get('documents.max_response_bytes', 15_728_640),
);
$pipeline = new DocumentPipeline(
    store: new DocumentStore(Database::connect($config)),
    downloader: new OfficialDocumentDownloader($http, $storageRoot),
    textExtractor: new PdftotextExtractor(
        binary: (string) $config->get('documents.pdftotext_binary', '/usr/bin/pdftotext'),
        temporaryDirectory: HALALPULSE_ROOT . '/storage/tmp',
        timeoutSeconds: (int) $config->get('documents.extraction_timeout_seconds', 30),
    ),
    metricExtractor: new MetricCandidateExtractor(),
    logger: $logger,
    storageRoot: $storageRoot,
);

$result = $pipeline->run($batchSize);
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;

exit($result['status'] === 'succeeded' || $result['status'] === 'skipped' ? 0 : 1);
