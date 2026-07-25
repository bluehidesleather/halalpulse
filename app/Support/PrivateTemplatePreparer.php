<?php

declare(strict_types=1);

namespace HalalPulse\Support;

use RuntimeException;
use Throwable;

final class PrivateTemplatePreparer
{
    public function prepare(string $source, string $destination): bool
    {
        if (is_file($destination)) {
            return false;
        }
        if (!is_file($source) || !is_readable($source)) {
            throw new RuntimeException('The safe template is missing or unreadable.');
        }
        if (is_link($destination)) {
            throw new RuntimeException('The private working file must not be a symbolic link.');
        }

        $contents = file_get_contents($source);
        if (!is_string($contents)) {
            throw new RuntimeException('Unable to read the safe template.');
        }

        $handle = fopen($destination, 'x');
        if ($handle === false) {
            if (is_file($destination)) {
                return false;
            }
            throw new RuntimeException('Unable to create the private working file.');
        }

        $complete = false;
        try {
            if (!chmod($destination, 0600)) {
                throw new RuntimeException('Unable to protect the private working file.');
            }
            $offset = 0;
            $length = strlen($contents);
            while ($offset < $length) {
                $written = fwrite($handle, substr($contents, $offset));
                if (!is_int($written) || $written < 1) {
                    throw new RuntimeException('Unable to write the complete private working file.');
                }
                $offset += $written;
            }
            if (!fflush($handle)) {
                throw new RuntimeException('Unable to flush the private working file.');
            }
            if (function_exists('fsync') && !fsync($handle)) {
                throw new RuntimeException('Unable to synchronize the private working file.');
            }
            $complete = true;
        } catch (Throwable $exception) {
            throw $exception;
        } finally {
            fclose($handle);
            if (!$complete) {
                @unlink($destination);
            }
        }

        return true;
    }
}
