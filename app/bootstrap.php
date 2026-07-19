<?php

declare(strict_types=1);

use HalalPulse\Config;

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

$localConfig = HALALPULSE_ROOT . '/config/config.local.php';
$exampleConfig = HALALPULSE_ROOT . '/config/config.example.php';
$configFile = is_file($localConfig) ? $localConfig : $exampleConfig;
$values = require $configFile;

if (!is_array($values)) {
    throw new RuntimeException('Configuration file must return an array.');
}

date_default_timezone_set((string) ($values['app']['timezone'] ?? 'Asia/Kolkata'));

return new Config($values);
