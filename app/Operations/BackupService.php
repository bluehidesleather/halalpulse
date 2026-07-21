<?php

declare(strict_types=1);

namespace HalalPulse\Operations;

use DateTimeImmutable;
use DateTimeZone;
use HalalPulse\Config;
use RuntimeException;
use Throwable;

final readonly class BackupService
{
    public function __construct(
        private Config $config,
        private BackupEncryptor $encryptor,
        private string $projectRoot,
    ) {
    }

    /**
     * @return array{
     *   status: string,
     *   path: string,
     *   filename: string,
     *   encrypted_sha256: string,
     *   plaintext_sha256: string,
     *   bytes: int,
     *   created_at: string,
     *   commit: string,
     *   removed_old_backups: int
     * }
     */
    public function create(): array
    {
        if ($this->config->get('backups.enabled', false) !== true) {
            throw new RuntimeException('Backups are disabled in private configuration.');
        }

        $storagePath = $this->storagePath();
        $this->ensurePrivateDirectory($storagePath);
        $lockPath = $storagePath . '/.backup.lock';
        $lock = fopen($lockPath, 'c+');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new RuntimeException('Another backup process is already running.');
        }
        @chmod($lockPath, 0600);

        $temporaryDirectory = $storagePath . '/.tmp-' . bin2hex(random_bytes(8));
        $this->ensurePrivateDirectory($temporaryDirectory);

        try {
            $databasePath = $temporaryDirectory . '/database.sql.gz';
            $privateFilesPath = $temporaryDirectory . '/private-files.tar.gz';
            $manifestPath = $temporaryDirectory . '/manifest.json';
            $bundlePath = $temporaryDirectory . '/bundle.tar.gz';

            $this->dumpDatabase($databasePath, $temporaryDirectory . '/mysqldump-error.log');
            $includedPaths = $this->archivePrivateFiles($privateFilesPath, $temporaryDirectory . '/tar-error.log');
            $createdAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM);
            $commit = $this->currentCommit();

            $manifest = [
                'format' => 'halalpulse-backup-v1',
                'created_at' => $createdAt,
                'application_commit' => $commit,
                'database' => [
                    'filename' => basename($databasePath),
                    'sha256' => $this->sha256($databasePath),
                    'bytes' => $this->fileBytes($databasePath),
                ],
                'private_files' => [
                    'filename' => basename($privateFilesPath),
                    'sha256' => $this->sha256($privateFilesPath),
                    'bytes' => $this->fileBytes($privateFilesPath),
                    'included_paths' => $includedPaths,
                ],
                'restore_warning' => 'Restore only into an isolated environment after independently verifying this manifest and the encrypted envelope.',
            ];
            $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (file_put_contents($manifestPath, $json . "\n", LOCK_EX) === false) {
                throw new RuntimeException('Unable to write backup manifest.');
            }
            @chmod($manifestPath, 0600);

            $this->runCommand([
                $this->binary('backups.tar_binary', '/usr/bin/tar'),
                '--create',
                '--gzip',
                '--file',
                $bundlePath,
                '--directory',
                $temporaryDirectory,
                basename($databasePath),
                basename($privateFilesPath),
                basename($manifestPath),
            ], $temporaryDirectory . '/bundle-error.log');
            @chmod($bundlePath, 0600);

            $plaintextSha256 = $this->sha256($bundlePath);
            $timestamp = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Ymd-His\Z');
            $commitLabel = preg_match('/^[a-f0-9]{7,64}$/D', $commit) === 1 ? substr($commit, 0, 12) : 'unknown';
            $filename = "halalpulse-{$timestamp}-{$commitLabel}.hpbak";
            $finalPath = $storagePath . '/' . $filename;
            $encryption = $this->encryptor->encrypt(
                $bundlePath,
                $finalPath,
                $this->config->requireString('backups.encryption_passphrase'),
            );
            if (!$this->encryptor->verify(
                $finalPath,
                $this->config->requireString('backups.encryption_passphrase'),
                $plaintextSha256,
            )) {
                @unlink($finalPath);
                throw new RuntimeException('Encrypted backup verification failed.');
            }

            $status = [
                'status' => 'succeeded',
                'path' => $finalPath,
                'filename' => $filename,
                'encrypted_sha256' => $encryption['sha256'],
                'plaintext_sha256' => $plaintextSha256,
                'bytes' => $encryption['bytes'],
                'created_at' => $createdAt,
                'commit' => $commit,
                'removed_old_backups' => $this->pruneOldBackups($storagePath, $finalPath),
            ];
            $this->writeLatestStatus($storagePath, $status);

            return $status;
        } finally {
            $this->removeTree($temporaryDirectory);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array<string, mixed>|null */
    public function latestStatus(): ?array
    {
        $path = $this->storagePath() . '/latest.json';
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }
        $json = file_get_contents($path);
        if (!is_string($json)) {
            return null;
        }
        try {
            $status = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return is_array($status) ? $status : null;
    }

    public function storagePath(): string
    {
        $configured = (string) $this->config->get('backups.storage_path', $this->projectRoot . '/storage/backups');
        $path = $this->normalizeAbsolutePath($configured);
        $publicRoot = $this->normalizeAbsolutePath($this->projectRoot . '/public_html');
        if ($path === $publicRoot || str_starts_with($path . '/', $publicRoot . '/')) {
            throw new RuntimeException('Backup storage must remain outside public_html.');
        }

        return $path;
    }

    private function dumpDatabase(string $destinationPath, string $errorPath): void
    {
        $database = [
            'host' => (string) $this->config->get('database.host', '127.0.0.1'),
            'port' => (int) $this->config->get('database.port', 3306),
            'name' => $this->config->requireString('database.name'),
            'user' => $this->config->requireString('database.user'),
            'password' => (string) $this->config->get('database.password', ''),
            'charset' => (string) $this->config->get('database.charset', 'utf8mb4'),
        ];
        $command = [
            $this->binary('backups.mysqldump_binary', '/usr/bin/mysqldump'),
            '--host=' . $database['host'],
            '--port=' . $database['port'],
            '--user=' . $database['user'],
            '--default-character-set=' . $database['charset'],
            '--single-transaction',
            '--quick',
            '--skip-lock-tables',
            '--hex-blob',
            '--routines',
            '--triggers',
            '--events',
            $database['name'],
        ];

        $error = fopen($errorPath, 'wb');
        if ($error === false) {
            throw new RuntimeException('Unable to create database-backup error file.');
        }
        @chmod($errorPath, 0600);
        $process = proc_open($command, [0 => ['file', '/dev/null', 'rb'], 1 => ['pipe', 'wb'], 2 => $error], $pipes, $this->projectRoot, [
            'MYSQL_PWD' => $database['password'],
            'PATH' => '/usr/local/bin:/usr/bin:/bin',
            'HOME' => dirname($this->projectRoot),
        ]);
        if (!is_resource($process)) {
            fclose($error);
            throw new RuntimeException('Unable to start mysqldump.');
        }

        $gzip = gzopen($destinationPath, 'wb9');
        if ($gzip === false) {
            fclose($pipes[1]);
            proc_terminate($process);
            proc_close($process);
            fclose($error);
            throw new RuntimeException('Unable to create compressed database backup.');
        }
        @chmod($destinationPath, 0600);

        try {
            while (!feof($pipes[1])) {
                $chunk = fread($pipes[1], 1048576);
                if ($chunk === false) {
                    throw new RuntimeException('Unable to read mysqldump output.');
                }
                if ($chunk !== '' && gzwrite($gzip, $chunk) === false) {
                    throw new RuntimeException('Unable to compress mysqldump output.');
                }
            }
        } catch (Throwable $exception) {
            fclose($pipes[1]);
            gzclose($gzip);
            proc_terminate($process);
            proc_close($process);
            fclose($error);
            @unlink($destinationPath);
            throw $exception;
        }

        fclose($pipes[1]);
        gzclose($gzip);
        $exitCode = proc_close($process);
        fclose($error);
        if ($exitCode !== 0 || !is_file($destinationPath) || $this->fileBytes($destinationPath) < 20) {
            $message = $this->safeErrorMessage($errorPath);
            @unlink($destinationPath);
            throw new RuntimeException('Database backup failed' . ($message === '' ? '.' : ': ' . $message));
        }
        @unlink($errorPath);
    }

    /** @return list<string> */
    private function archivePrivateFiles(string $destinationPath, string $errorPath): array
    {
        $configured = $this->config->get('backups.include_paths', [
            'config/config.local.php',
            'storage/documents',
            'storage/xbrl',
        ]);
        if (!is_array($configured)) {
            throw new RuntimeException('backups.include_paths must be a list.');
        }

        $relativePaths = [];
        foreach ($configured as $candidate) {
            if (!is_string($candidate) || trim($candidate) === '') {
                continue;
            }
            $relative = $this->validatedRelativePath($candidate);
            $absolute = $this->projectRoot . '/' . $relative;
            if (file_exists($absolute)) {
                $relativePaths[] = $relative;
            }
        }
        $relativePaths = array_values(array_unique($relativePaths));
        sort($relativePaths);
        if ($relativePaths === []) {
            throw new RuntimeException('No configured private backup paths currently exist.');
        }

        $command = [
            $this->binary('backups.tar_binary', '/usr/bin/tar'),
            '--create',
            '--gzip',
            '--file',
            $destinationPath,
            '--directory',
            $this->projectRoot,
            '--exclude=storage/backups',
            '--exclude=storage/logs',
            '--exclude=.git',
            ...$relativePaths,
        ];
        $this->runCommand($command, $errorPath);
        @chmod($destinationPath, 0600);

        return $relativePaths;
    }

    /** @param list<string> $command */
    private function runCommand(array $command, string $errorPath): void
    {
        $error = fopen($errorPath, 'wb');
        if ($error === false) {
            throw new RuntimeException('Unable to create command error file.');
        }
        @chmod($errorPath, 0600);
        $process = proc_open(
            $command,
            [0 => ['file', '/dev/null', 'rb'], 1 => ['file', '/dev/null', 'wb'], 2 => $error],
            $pipes,
            $this->projectRoot,
            ['PATH' => '/usr/local/bin:/usr/bin:/bin', 'HOME' => dirname($this->projectRoot)],
        );
        if (!is_resource($process)) {
            fclose($error);
            throw new RuntimeException('Unable to start backup command.');
        }
        $exitCode = proc_close($process);
        fclose($error);
        if ($exitCode !== 0) {
            $message = $this->safeErrorMessage($errorPath);
            throw new RuntimeException('Backup command failed' . ($message === '' ? '.' : ': ' . $message));
        }
        @unlink($errorPath);
    }

    private function binary(string $configKey, string $default): string
    {
        $path = (string) $this->config->get($configKey, $default);
        if ($path === '' || !is_file($path) || !is_executable($path)) {
            throw new RuntimeException("Configured backup binary is unavailable: {$configKey}");
        }

        return $path;
    }

    private function currentCommit(): string
    {
        $process = proc_open(
            ['/usr/bin/git', '-C', $this->projectRoot, 'rev-parse', 'HEAD'],
            [0 => ['file', '/dev/null', 'rb'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'wb']],
            $pipes,
        );
        if (!is_resource($process)) {
            return 'unknown';
        }
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $exitCode = proc_close($process);
        $commit = is_string($output) ? trim($output) : '';

        return $exitCode === 0 && preg_match('/^[a-f0-9]{40}$/D', $commit) === 1 ? $commit : 'unknown';
    }

    /** @param array<string, mixed> $status */
    private function writeLatestStatus(string $storagePath, array $status): void
    {
        $temporary = $storagePath . '/.latest-' . bin2hex(random_bytes(6)) . '.json';
        $json = json_encode($status, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (file_put_contents($temporary, $json . "\n", LOCK_EX) === false) {
            throw new RuntimeException('Unable to write backup status.');
        }
        @chmod($temporary, 0600);
        if (!rename($temporary, $storagePath . '/latest.json')) {
            @unlink($temporary);
            throw new RuntimeException('Unable to publish backup status.');
        }
        @chmod($storagePath . '/latest.json', 0600);
    }

    private function pruneOldBackups(string $storagePath, string $keepPath): int
    {
        $retentionDays = max(1, min(3650, (int) $this->config->get('backups.retention_days', 14)));
        $cutoff = time() - ($retentionDays * 86400);
        $removed = 0;
        $files = glob($storagePath . '/halalpulse-*.hpbak');
        if (!is_array($files)) {
            return 0;
        }

        foreach ($files as $path) {
            if ($path === $keepPath) {
                continue;
            }
            $modified = filemtime($path);
            if (is_int($modified) && $modified < $cutoff && @unlink($path)) {
                $removed++;
            }
        }

        return $removed;
    }

    private function ensurePrivateDirectory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
            throw new RuntimeException('Unable to create private backup directory.');
        }
        @chmod($path, 0700);
        if (!is_writable($path)) {
            throw new RuntimeException('Private backup directory is not writable.');
        }
    }

    private function validatedRelativePath(string $input): string
    {
        $path = str_replace('\\', '/', trim($input));
        $path = ltrim($path, '/');
        if ($path === '' || str_contains($path, "\0") || preg_match('#(?:^|/)\.\.(?:/|$)#', $path) === 1) {
            throw new RuntimeException('Backup include path is invalid.');
        }
        $absolute = $this->normalizeAbsolutePath($this->projectRoot . '/' . $path);
        $root = rtrim($this->normalizeAbsolutePath($this->projectRoot), '/');
        if ($absolute !== $root && !str_starts_with($absolute . '/', $root . '/')) {
            throw new RuntimeException('Backup include path escapes the project root.');
        }
        if ($path === 'storage/backups' || str_starts_with($path, 'storage/backups/')) {
            throw new RuntimeException('Backup archives cannot include the backup directory itself.');
        }

        return $path;
    }

    private function normalizeAbsolutePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $parts = [];
        foreach (explode('/', $path) as $part) {
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

    private function sha256(string $path): string
    {
        $hash = hash_file('sha256', $path);
        if (!is_string($hash)) {
            throw new RuntimeException('Unable to hash backup file.');
        }

        return $hash;
    }

    private function fileBytes(string $path): int
    {
        $bytes = filesize($path);
        if (!is_int($bytes)) {
            throw new RuntimeException('Unable to measure backup file.');
        }

        return $bytes;
    }

    private function safeErrorMessage(string $path): string
    {
        if (!is_file($path)) {
            return '';
        }
        $message = file_get_contents($path, false, null, 0, 2000);
        if (!is_string($message)) {
            return '';
        }
        $message = preg_replace('/[^\P{C}\t\r\n]/u', '', $message);

        return trim(is_string($message) ? $message : '');
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);
            return;
        }
        $items = scandir($path);
        if (!is_array($items)) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $child = $path . '/' . $item;
            if (is_dir($child) && !is_link($child)) {
                $this->removeTree($child);
            } else {
                @unlink($child);
            }
        }
        @rmdir($path);
    }
}
