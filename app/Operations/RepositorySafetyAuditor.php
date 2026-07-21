<?php

declare(strict_types=1);

namespace HalalPulse\Operations;

final class RepositorySafetyAuditor
{
    /** @var list<string> */
    private const REQUIRED_IGNORE_RULES = [
        '/config/config.local.php',
        '/config/sharia-policy.local.json',
        '/config/multibagger-methodology.local.json',
        '/storage/documents/*',
        '/storage/xbrl/*',
        '/storage/backups/*',
    ];

    /** @var array<string, list<string>> */
    private const ALLOWED_CONTENT_FINDINGS = [
        'tests/operations-readiness.php' => ['Hostinger temporary domain'],
    ];

    /**
     * @param list<array{path:string,mode:string}> $entries
     * @param callable(string):(?string) $readFile
     * @return array{passed:bool,files_scanned:int,failures:list<string>,warnings:list<string>}
     */
    public function audit(array $entries, callable $readFile): array
    {
        $failures = [];
        $warnings = [];
        $filesScanned = 0;
        $entryByPath = [];

        foreach ($entries as $entry) {
            $path = $this->normalizePath($entry['path'] ?? '');
            $mode = (string) ($entry['mode'] ?? '');
            if ($path === '') {
                $failures[] = 'A tracked Git entry has an empty or invalid path.';
                continue;
            }
            $entryByPath[$path] = ['path' => $path, 'mode' => $mode];

            $pathFailure = $this->forbiddenPathReason($path);
            if ($pathFailure !== null) {
                $failures[] = "{$path}: {$pathFailure}";
            }

            $content = $readFile($path);
            if (!is_string($content)) {
                $warnings[] = "{$path}: tracked content could not be read for safety scanning.";
                continue;
            }
            if (str_contains($content, "\0")) {
                continue;
            }
            $filesScanned++;

            foreach ($this->contentPatterns() as $label => $pattern) {
                if (preg_match($pattern, $content) !== 1) {
                    continue;
                }
                if ($this->isAllowedFinding($path, $label)) {
                    continue;
                }
                $failures[] = "{$path}: detected {$label}.";
            }
        }

        $wrapper = $entryByPath['bin/mysqldump-no-tablespaces'] ?? null;
        if ($wrapper === null) {
            $failures[] = 'bin/mysqldump-no-tablespaces is not tracked.';
        } elseif ($wrapper['mode'] !== '100755') {
            $failures[] = 'bin/mysqldump-no-tablespaces must be tracked with executable mode 100755.';
        }

        $gitignore = $readFile('.gitignore');
        if (!is_string($gitignore)) {
            $failures[] = '.gitignore is missing or unreadable.';
        } else {
            $lines = preg_split('/\R/', $gitignore) ?: [];
            $normalizedRules = array_map('trim', $lines);
            foreach (self::REQUIRED_IGNORE_RULES as $rule) {
                if (!in_array($rule, $normalizedRules, true)) {
                    $failures[] = ".gitignore must contain {$rule}.";
                }
            }
        }

        $failures = array_values(array_unique($failures));
        $warnings = array_values(array_unique($warnings));
        sort($failures);
        sort($warnings);

        return [
            'passed' => $failures === [],
            'files_scanned' => $filesScanned,
            'failures' => $failures,
            'warnings' => $warnings,
        ];
    }

    private function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        while (str_starts_with($path, './')) {
            $path = substr($path, 2);
        }
        $path = ltrim($path, '/');

        return str_contains($path, "\0") ? '' : $path;
    }

    private function forbiddenPathReason(string $path): ?string
    {
        $exact = [
            'config/config.local.php',
            'config/sharia-policy.local.json',
            'config/multibagger-methodology.local.json',
            '.env',
            '.htpasswd',
            'id_rsa',
            'id_ed25519',
        ];
        if (in_array($path, $exact, true)) {
            return 'private configuration or credential material must not be tracked.';
        }

        $basename = strtolower(basename($path));
        if (preg_match('/^\.env\.(?!example$|sample$)/i', $basename) === 1) {
            return 'environment files containing private values must not be tracked.';
        }

        if (str_starts_with($path, 'storage/') && $basename !== '.gitkeep') {
            return 'runtime evidence, logs, and backups must remain outside Git.';
        }

        if (preg_match('/(?:\.hpbak|\.sql\.gz|\.tar\.gz|\.tgz|\.zip|\.7z|\.rar|\.p12|\.pfx|\.kdbx|\.pem|\.key|\.bak)$/i', $path) === 1) {
            return 'backup archives, private keys, or credential containers must not be tracked.';
        }

        return null;
    }

    /** @return array<string, string> */
    private function contentPatterns(): array
    {
        return [
            'private key material' => '/-----BEGIN(?: [A-Z0-9]+)* PRIVATE KEY-----/',
            'GitHub access token' => '/\b(?:gh[pousr]_[A-Za-z0-9]{20,}|github_pat_[A-Za-z0-9_]{20,})\b/',
            'AWS access key' => '/\b(?:AKIA|ASIA)[A-Z0-9]{16}\b/',
            'Google API key' => '/\bAIza[0-9A-Za-z_-]{35}\b/',
            'Slack access token' => '/\bxox[baprs]-[A-Za-z0-9-]{20,}\b/',
            'Stripe live secret' => '/\bsk_live_[A-Za-z0-9]{16,}\b/',
            'Telegram bot token' => '/(?<![A-Za-z0-9])\d{8,12}:[A-Za-z0-9_-]{30,}(?![A-Za-z0-9])/',
            'credential-bearing URL' => '#https?://[^/\s:@]+:[^/\s@]+@#i',
            'Hostinger temporary domain' => '/\b[a-z0-9-]+\.hostingersite\.com\b/i',
            'Hostinger production account path' => '#/home/u\d{6,}/#',
        ];
    }

    private function isAllowedFinding(string $path, string $label): bool
    {
        return in_array($label, self::ALLOWED_CONTENT_FINDINGS[$path] ?? [], true);
    }
}
