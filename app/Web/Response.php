<?php

declare(strict_types=1);

namespace HalalPulse\Web;

use InvalidArgumentException;

final class Response
{
    public static function redirect(string $path, int $status = 303): never
    {
        if (!str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, "\r") || str_contains($path, "\n")) {
            throw new InvalidArgumentException('Redirect target must be a local absolute path.');
        }

        header('Location: ' . $path, true, $status);
        exit;
    }
}
