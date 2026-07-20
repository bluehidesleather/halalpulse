<?php

declare(strict_types=1);

namespace HalalPulse;

use InvalidArgumentException;

final class Config
{
    public function __construct(private readonly array $values)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->values;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    public function requireString(string $key): string
    {
        $value = $this->get($key);

        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException("Missing required configuration value: {$key}");
        }

        return $value;
    }
}
