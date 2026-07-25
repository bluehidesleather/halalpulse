<?php

declare(strict_types=1);

namespace HalalPulse\Support;

use RuntimeException;

final class CliSecretPrompt
{
    public static function readConfirmed(string $label, int $minimumLength = 24, int $maximumLength = 1024): string
    {
        $first = self::readHidden($label, $minimumLength, $maximumLength);
        $second = self::readHidden('Confirm ' . lcfirst($label), $minimumLength, $maximumLength);
        if (!hash_equals($first, $second)) {
            self::wipe($first);
            self::wipe($second);
            throw new RuntimeException('The two secret values do not match.');
        }
        self::wipe($second);

        return $first;
    }

    public static function readHidden(string $label, int $minimumLength = 1, int $maximumLength = 4096): string
    {
        if (PHP_SAPI !== 'cli' || !function_exists('stream_isatty') || !stream_isatty(STDIN)) {
            throw new RuntimeException('A private interactive terminal is required.');
        }
        if ($minimumLength < 1 || $maximumLength < $minimumLength) {
            throw new RuntimeException('Secret length policy is invalid.');
        }

        fwrite(STDOUT, rtrim($label, ': ') . ': ');
        self::setEcho(false);
        try {
            $value = fgets(STDIN, $maximumLength + 2);
        } finally {
            self::setEcho(true);
            fwrite(STDOUT, "\n");
        }

        if (!is_string($value)) {
            throw new RuntimeException('Unable to read the secret value.');
        }
        $value = rtrim($value, "\r\n");
        $length = strlen($value);
        if ($length < $minimumLength || $length > $maximumLength) {
            self::wipe($value);
            throw new RuntimeException("The secret must be between {$minimumLength} and {$maximumLength} bytes.");
        }
        if (preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            self::wipe($value);
            throw new RuntimeException('The secret contains a forbidden control character.');
        }

        return $value;
    }

    public static function wipe(string &$value): void
    {
        if (function_exists('sodium_memzero')) {
            sodium_memzero($value);
        }
        $value = '';
    }

    private static function setEcho(bool $enabled): void
    {
        $mode = $enabled ? 'echo' : '-echo';
        $process = proc_open(
            ['/usr/bin/stty', $mode],
            [0 => STDIN, 1 => ['file', '/dev/null', 'wb'], 2 => ['file', '/dev/null', 'wb']],
            $pipes,
        );
        if (!is_resource($process) || proc_close($process) !== 0) {
            throw new RuntimeException('Unable to control terminal echo securely.');
        }
    }
}
