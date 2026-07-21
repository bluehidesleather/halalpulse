#!/usr/bin/env php
<?php

declare(strict_types=1);

use HalalPulse\Database;
use HalalPulse\Sharia\NseShariaEvidenceMapper;
use HalalPulse\Sharia\ShariaCandidateBackfillService;
use HalalPulse\Sharia\ShariaInputCandidateStore;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$config = require dirname(__DIR__) . '/app/bootstrap.php';
$limit = 500;
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--limit=(\d{1,4})$/D', $argument, $matches) === 1) {
        $limit = (int) $matches[1];
        continue;
    }

    fwrite(STDERR, "Usage: php cron/backfill-sharia-candidates.php [--limit=500]\n");
    exit(2);
}

try {
    $pdo = Database::connect($config);
    $result = (new ShariaCandidateBackfillService(
        pdo: $pdo,
        mapper: new NseShariaEvidenceMapper(),
        store: new ShariaInputCandidateStore($pdo),
    ))->run($limit);

    echo json_encode(
        ['status' => $result['failed'] > 0 ? 'partial' : 'succeeded'] + $result,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
    ) . PHP_EOL;
    exit($result['failed'] > 0 ? 1 : 0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Sharia candidate backfill failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
