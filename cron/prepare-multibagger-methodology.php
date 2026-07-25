#!/usr/bin/env php
<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

$source = HALALPULSE_ROOT . '/config/multibagger-methodology.example.json';
$destination = HALALPULSE_ROOT . '/config/multibagger-methodology.local.json';

try {
    if (is_file($destination)) {
        fwrite(STDOUT, "Private multibagger methodology working file already exists. It was not overwritten.\n");
        fwrite(STDOUT, "Check it with: php cron/check-multibagger-methodology.php\n");
        exit(0);
    }
    $json = file_get_contents($source);
    if (!is_string($json)) {
        throw new RuntimeException('Unable to read the safe methodology template.');
    }
    $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($payload)) {
        throw new RuntimeException('The safe methodology template must contain a JSON object.');
    }
    $handle = fopen($destination, 'x');
    if ($handle === false) {
        throw new RuntimeException('Unable to create the private methodology working file.');
    }
    try {
        if (fwrite($handle, $json) !== strlen($json)) {
            throw new RuntimeException('Unable to write the complete methodology working file.');
        }
        fflush($handle);
    } finally {
        fclose($handle);
    }
    if (!chmod($destination, 0600)) {
        @unlink($destination);
        throw new RuntimeException('Unable to protect the private methodology working file.');
    }

    fwrite(STDOUT, "Created ignored config/multibagger-methodology.local.json with mode 0600.\n");
    fwrite(STDOUT, "Review every factor, weight, evidence requirement, grade anchor, valuation rule, market-cap band, and microcap adjustment.\n");
    fwrite(STDOUT, "Then run: php cron/check-multibagger-methodology.php\n");
    fwrite(STDOUT, "Do not run the installer until the checker reports [READY] and independent review is complete.\n");
} catch (Throwable $exception) {
    fwrite(STDERR, "Methodology preparation failed: {$exception->getMessage()}\n");
    exit(1);
}
