<?php

declare(strict_types=1);

namespace HalalPulse\Support;

use RuntimeException;
use Throwable;

final readonly class PrivateConfigEditor
{
    public function __construct(private string $projectRoot)
    {
    }

    /** @param array<string, mixed> $patch */
    public function apply(array $patch): void
    {
        $path = $this->projectRoot . '/config/config.local.php';
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Private configuration file is missing or unreadable.');
        }
        if (is_link($path)) {
            throw new RuntimeException('Private configuration must not be a symbolic link.');
        }

        $config = require $path;
        if (!is_array($config)) {
            throw new RuntimeException('Private configuration must return an array.');
        }

        $updated = $this->merge($config, $patch);
        $serialized = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($updated, true) . ";\n";
        $directory = dirname($path);
        $temporary = $directory . '/.config.local.' . bin2hex(random_bytes(8)) . '.tmp';
        $handle = fopen($temporary, 'x');
        if ($handle === false) {
            throw new RuntimeException('Unable to create the temporary private configuration.');
        }

        $readyToPublish = false;
        try {
            if (!chmod($temporary, 0600)) {
                throw new RuntimeException('Unable to protect the temporary private configuration.');
            }
            $offset = 0;
            $length = strlen($serialized);
            while ($offset < $length) {
                $written = fwrite($handle, substr($serialized, $offset));
                if (!is_int($written) || $written < 1) {
                    throw new RuntimeException('Unable to write the complete private configuration.');
                }
                $offset += $written;
            }
            if (!fflush($handle)) {
                throw new RuntimeException('Unable to flush the temporary private configuration.');
            }
            if (function_exists('fsync') && !fsync($handle)) {
                throw new RuntimeException('Unable to synchronize the temporary private configuration.');
            }
            $readyToPublish = true;
        } catch (Throwable $exception) {
            throw $exception;
        } finally {
            fclose($handle);
            if (!$readyToPublish) {
                @unlink($temporary);
            }
        }

        try {
            if (!rename($temporary, $path)) {
                throw new RuntimeException('Unable to publish the private configuration atomically.');
            }
            @chmod($path, 0600);
            clearstatcache(true, $path);
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    /**
     * @param array<mixed> $base
     * @param array<mixed> $patch
     * @return array<mixed>
     */
    private function merge(array $base, array $patch): array
    {
        foreach ($patch as $key => $value) {
            if (is_array($value)
                && !array_is_list($value)
                && isset($base[$key])
                && is_array($base[$key])
                && !array_is_list($base[$key])) {
                $base[$key] = $this->merge($base[$key], $value);
                continue;
            }
            $base[$key] = $value;
        }

        return $base;
    }
}
