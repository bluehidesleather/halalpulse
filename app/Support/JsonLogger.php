<?php

declare(strict_types=1);

namespace HalalPulse\Support;

use DateTimeImmutable;
use RuntimeException;

final class JsonLogger
{
    public function __construct(private readonly string $path)
    {
    }

    public function info(string $message, array $context = []): void
    {
        $this->write('info', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->write('error', $message, $context);
    }

    private function write(string $level, string $message, array $context): void
    {
        $directory = dirname($this->path);

        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException("Unable to create log directory: {$directory}");
        }

        $record = [
            'timestamp' => (new DateTimeImmutable())->format(DATE_ATOM),
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ];

        $json = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        if (file_put_contents($this->path, $json . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException("Unable to write log file: {$this->path}");
        }
    }
}
