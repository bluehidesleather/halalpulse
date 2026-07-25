#!/usr/bin/env php
<?php

declare(strict_types=1);

use HalalPulse\Support\PrivateTemplatePreparer;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

$source = HALALPULSE_ROOT . '/config/sharia-policy.example.json';
$destination = HALALPULSE_ROOT . '/config/sharia-policy.local.json';

try {
    $json = file_get_contents($source);
    if (!is_string($json)) {
        throw new RuntimeException('Unable to read the safe policy template.');
    }
    $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($payload)) {
        throw new RuntimeException('The safe policy template must contain a JSON object.');
    }

    $created = (new PrivateTemplatePreparer())->prepare($source, $destination);
    if (!$created) {
        fwrite(STDOUT, "Private Sharia policy working file already exists. It was not overwritten.\n");
        fwrite(STDOUT, "Check it with: php cron/check-sharia-policy.php\n");
        exit(0);
    }

    fwrite(STDOUT, "Created ignored config/sharia-policy.local.json with mode 0600.\n");
    fwrite(STDOUT, "Replace every placeholder only from the exact official/licensed AAOIFI edition and obtain competent review.\n");
    fwrite(STDOUT, "Then run: php cron/check-sharia-policy.php\n");
    fwrite(STDOUT, "Do not run the installer until the checker reports [READY] and approval is complete.\n");
} catch (Throwable $exception) {
    fwrite(STDERR, "Sharia policy preparation failed: {$exception->getMessage()}\n");
    exit(1);
}
