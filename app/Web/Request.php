<?php

declare(strict_types=1);

namespace HalalPulse\Web;

final class Request
{
    public static function isPost(): bool
    {
        return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST';
    }

    public static function postString(string $key, bool $trim = true): string
    {
        $value = $_POST[$key] ?? '';
        if (!is_string($value)) {
            return '';
        }

        return $trim ? trim($value) : $value;
    }

    public static function queryString(string $key): string
    {
        $value = $_GET[$key] ?? '';

        return is_string($value) ? trim($value) : '';
    }

    public static function queryInt(string $key, int $default = 1): int
    {
        $value = filter_input(INPUT_GET, $key, FILTER_VALIDATE_INT);

        return is_int($value) ? $value : $default;
    }

    public static function clientIp(): string
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');

        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '0.0.0.0';
    }
}
