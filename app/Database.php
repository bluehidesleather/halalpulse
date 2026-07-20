<?php

declare(strict_types=1);

namespace HalalPulse;

use InvalidArgumentException;
use PDO;

final class Database
{
    public static function connect(Config $config): PDO
    {
        $host = $config->requireString('database.host');
        $port = (int) $config->get('database.port', 3306);
        $name = $config->requireString('database.name');
        $charset = (string) $config->get('database.charset', 'utf8mb4');
        $user = $config->requireString('database.user');
        $password = (string) $config->get('database.password', '');

        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";

        $pdo = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        $sessionTimezone = (string) $config->get('database.session_timezone', '+05:30');
        if (!self::isValidTimezoneOffset($sessionTimezone)) {
            throw new InvalidArgumentException('database.session_timezone must be a valid UTC offset from -13:59 to +14:00.');
        }
        $pdo->exec('SET time_zone = ' . $pdo->quote($sessionTimezone));

        return $pdo;
    }

    public static function isValidTimezoneOffset(string $value): bool
    {
        return preg_match('/^(?:[+-](?:0\\d|1[0-3]):[0-5]\\d|\\+14:00)$/D', $value) === 1;
    }
}
