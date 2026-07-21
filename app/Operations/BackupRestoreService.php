<?php

declare(strict_types=1);

namespace HalalPulse\Operations;

use HalalPulse\Config;
use RuntimeException;
use Throwable;

final readonly class BackupRestoreService
{
    public function __construct(
        private Config $config,
        private BackupEncryptor $encryptor,
        private string $projectRoot,
    ) {
    }

    /** @return array{status: string, destination: string, created_at: string, commit: string, files: list<string>} */
    public function extract(string $backupPath, string $destination): array
    {
        $backupPath = $this->realBackupPath($backupPath);
        $destination = $this->prepareDestination($destination);
        $temporaryBundle = $destination . '/.bundle-' . bin2hex(random_bytes(8)) . '.tar.gz';

        try {
            $this->encryptor->decrypt(
                $backupPath,
                $temporaryBundle,
                $this->config->requireString('backups.encryption_passphrase'),
            );
            @chmod($temporaryBundle, 0600);
            $entries = $this->listArchive($temporaryBundle);
            $expected = ['database.sql.gz', 'manifest.json', 'private-files.tar.gz'];
            sort($entries);
            sort($expected);
            if ($entries !== $expected) {
                throw new RuntimeException('Backup bundle contains unexpected or missing entries.');
            }

            $this->runCommand([
                $this->tarBinary(),
                '--extract',
                '--gzip',
                '--file',
                $temporaryBundle,
                '--directory',
                $destination,
                '--no-same-owner',
                '--no-same-permissions',
            ]);
            @unlink($temporaryBundle);

            $manifestPath = $destination . '/manifest.json';
            $json = file_get_contents($manifestPath);
            if (!is_string($json)) {
                throw new RuntimeException('Backup manifest cannot be read.');
            }
            $manifest = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($manifest) || ($manifest['format'] ?? null) !== 'halalpulse-backup-v1') {
                throw new RuntimeException('Backup manifest format is invalid.');
            }

            foreach (['database', 'private_files'] as $section) {
                $item = $manifest[$section] ?? null;
                if (!is_array($item)) {
                    throw new RuntimeException("Backup manifest section {$section} is missing.");
                }
                $filename = (string) ($item['filename'] ?? '');
                $expectedHash = (string) ($item['sha256'] ?? '');
                if (!in_array($filename, ['database.sql.gz', 'private-files.tar.gz'], true)) {
                    throw new RuntimeException("Backup manifest filename for {$section} is invalid.");
                }
                $path = $destination . '/' . $filename;
                $actualHash = hash_file('sha256', $path);
                if (!is_string($actualHash) || strlen($expectedHash) !== 64 || !hash_equals($expectedHash, $actualHash)) {
                    throw new RuntimeException("Backup manifest hash failed for {$filename}.");
                }
                @chmod($path, 0600);
            }
            @chmod($manifestPath, 0600);

            return [
                'status' => 'succeeded',
                'destination' => $destination,
                'created_at' => (string) ($manifest['created_at'] ?? ''),
                'commit' => (string) ($manifest['application_commit'] ?? 'unknown'),
                'files' => $expected,
            ];
        } catch (Throwable $exception) {
            @unlink($temporaryBundle);
            throw $exception;
        }
    }

    private function realBackupPath(string $path): string
    {
        $real = realpath($path);
        if ($real === false || !is_file($real) || !is_readable($real) || !str_ends_with($real, '.hpbak')) {
            throw new RuntimeException('Encrypted backup file is invalid or unreadable.');
        }

        $storage = realpath((string) $this->config->get('backups.storage_path', $this->projectRoot . '/storage/backups'));
        if ($storage === false || ($real !== $storage && !str_starts_with($real . '/', rtrim($storage, '/') . '/'))) {
            throw new RuntimeException('Backup file must be inside the configured private backup directory.');
        }

        return $real;
    }

    private function prepareDestination(string $path): string
    {
        $path = trim($path);
        if ($path === '' || $path[0] !== '/') {
            throw new RuntimeException('Extraction destination must be an absolute path.');
        }
        $normalized = $this->normalize($path);
        $project = rtrim($this->normalize($this->projectRoot), '/');
        if ($normalized === $project || str_starts_with($normalized . '/', $project . '/')) {
            throw new RuntimeException('Extract into an isolated directory outside the live project.');
        }
        if (file_exists($normalized)) {
            if (!is_dir($normalized)) {
                throw new RuntimeException('Extraction destination exists and is not a directory.');
            }
            $items = array_values(array_diff(scandir($normalized) ?: [], ['.', '..']));
            if ($items !== []) {
                throw new RuntimeException('Extraction destination must be empty.');
            }
        } elseif (!mkdir($normalized, 0700, true) && !is_dir($normalized)) {
            throw new RuntimeException('Unable to create extraction destination.');
        }
        @chmod($normalized, 0700);

        return $normalized;
    }

    /** @return list<string> */
    private function listArchive(string $path): array
    {
        $process = proc_open(
            [$this->tarBinary(), '--list', '--gzip', '--file', $path],
            [0 => ['file', '/dev/null', 'rb'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->projectRoot,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to inspect backup archive.');
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        if ($exitCode !== 0 || !is_string($stdout)) {
            throw new RuntimeException('Unable to inspect backup archive: ' . trim(is_string($stderr) ? $stderr : 'unknown error'));
        }

        $entries = [];
        foreach (preg_split('/\R/', trim($stdout)) ?: [] as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            if ($entry[0] === '/' || str_contains($entry, "\0") || preg_match('#(?:^|/)\.\.(?:/|$)#', $entry) === 1) {
                throw new RuntimeException('Backup archive contains an unsafe path.');
            }
            $entries[] = rtrim($entry, '/');
        }

        return array_values(array_unique($entries));
    }

    /** @param list<string> $command */
    private function runCommand(array $command): void
    {
        $process = proc_open(
            $command,
            [0 => ['file', '/dev/null', 'rb'], 1 => ['file', '/dev/null', 'wb'], 2 => ['pipe', 'w']],
            $pipes,
            $this->projectRoot,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to run backup extraction command.');
        }
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            throw new RuntimeException('Backup extraction failed: ' . trim(is_string($stderr) ? $stderr : 'unknown error'));
        }
    }

    private function tarBinary(): string
    {
        $path = (string) $this->config->get('backups.tar_binary', '/usr/bin/tar');
        if (!is_file($path) || !is_executable($path)) {
            throw new RuntimeException('Configured tar binary is unavailable.');
        }

        return $path;
    }

    private function normalize(string $path): string
    {
        $parts = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }

        return '/' . implode('/', $parts);
    }
}
