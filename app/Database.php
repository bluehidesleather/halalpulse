<?php

declare(strict_types=1);

namespace HalalPulse;

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

        return new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
}
