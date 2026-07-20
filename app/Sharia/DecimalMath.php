<?php

declare(strict_types=1);

namespace HalalPulse\Sharia;

use InvalidArgumentException;
use RuntimeException;

final class DecimalMath
{
    public function __construct(private readonly int $scale = 8)
    {
        if (!extension_loaded('bcmath')) {
            throw new RuntimeException('The bcmath PHP extension is required for exact Sharia ratio calculations.');
        }
    }

    public static function isDecimal(string $value): bool
    {
        return preg_match('/^(?:0|[1-9]\d*)(?:\.\d+)?$/D', $value) === 1;
    }

    public function compare(string $left, string $right): int
    {
        $this->assertDecimal($left);
        $this->assertDecimal($right);

        return bccomp($left, $right, $this->scale);
    }

    public function multiply(string $left, string $right): string
    {
        $this->assertDecimal($left);
        $this->assertDecimal($right);

        return bcmul($left, $right, $this->scale);
    }

    public function divide(string $numerator, string $denominator): string
    {
        $this->assertDecimal($numerator);
        $this->assertDecimal($denominator);
        if ($this->compare($denominator, '0') <= 0) {
            throw new InvalidArgumentException('A decimal denominator must be greater than zero.');
        }

        return bcdiv($numerator, $denominator, $this->scale);
    }

    public function squareRoot(string $value): string
    {
        $this->assertDecimal($value);

        return bcsqrt($value, $this->scale);
    }

    public function percent(string $numerator, string $denominator): string
    {
        $this->assertDecimal($numerator);
        $this->assertDecimal($denominator);

        if ($this->compare($denominator, '0') <= 0) {
            throw new InvalidArgumentException('A ratio denominator must be greater than zero.');
        }

        return bcmul(bcdiv($numerator, $denominator, $this->scale + 4), '100', $this->scale);
    }

    public function utilization(string $percentage, string $maximumPercentage): string
    {
        return $this->percent($percentage, $maximumPercentage);
    }

    public function normalize(string $value): string
    {
        $this->assertDecimal($value);

        if (!str_contains($value, '.')) {
            return $value;
        }

        $normalized = rtrim(rtrim($value, '0'), '.');

        return $normalized === '' ? '0' : $normalized;
    }

    private function assertDecimal(string $value): void
    {
        if (!self::isDecimal($value)) {
            throw new InvalidArgumentException('Expected a non-negative base-10 decimal string.');
        }
    }
}
