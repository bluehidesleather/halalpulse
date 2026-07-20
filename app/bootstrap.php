<?php

declare(strict_types=1);

use HalalPulse\Config;
use HalalPulse\Web\TransportSecurity;

define('HALALPULSE_ROOT', dirname(__DIR__));

spl_autoload_register(static function (string $class): void {
    $prefix = 'HalalPulse\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $path = HALALPULSE_ROOT . '/app/' . str_replace('\\', '/', $relativeClass) . '.php';

    if (is_file($path)) {
        require $path;
    }
});

$isWebRequest = PHP_SAPI !== 'cli';
if ($isWebRequest) {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    set_exception_handler(static function (Throwable $exception): void {
        try {
            $reference = bin2hex(random_bytes(8));
        } catch (Throwable) {
            $reference = substr(hash('sha256', uniqid('', true)), 0, 16);
        }

        error_log(sprintf(
            'HalalPulse unhandled error [%s] %s in %s:%d',
            $reference,
            $exception::class,
            $exception->getFile(),
            $exception->getLine(),
        ));

        if (!headers_sent()) {
            http_response_code(503);
            header('Content-Type: text/plain; charset=UTF-8');
            header('Cache-Control: no-store, max-age=0');
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: DENY');
            header('X-Robots-Tag: noindex, nofollow, noarchive');
            header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
        }

        echo "HalalPulse is temporarily unavailable. Reference: {$reference}\n";
    });
}

$localConfig = HALALPULSE_ROOT . '/config/config.local.php';
$exampleConfig = HALALPULSE_ROOT . '/config/config.example.php';
$configFile = is_file($localConfig) ? $localConfig : $exampleConfig;
$values = require $configFile;

if (!is_array($values)) {
    throw new RuntimeException('Configuration file must return an array.');
}

date_default_timezone_set((string) ($values['app']['timezone'] ?? 'Asia/Kolkata'));

if ($isWebRequest) {
    TransportSecurity::enforce((bool) ($values['app']['force_https'] ?? true), $_SERVER);
}

return new Config($values);
