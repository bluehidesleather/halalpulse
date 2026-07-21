<?php

declare(strict_types=1);

namespace HalalPulse\Http;

use RuntimeException;

final readonly class HttpRequestPolicy
{
    /** @var list<string> */
    private array $allowedHosts;

    /** @param list<string> $allowedHosts */
    public function __construct(array $allowedHosts)
    {
        $normalized = [];
        foreach ($allowedHosts as $host) {
            $host = strtolower(trim($host));
            if ($host === '' || strlen($host) > 253 || preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))+$/D', $host) !== 1) {
                throw new RuntimeException('HTTP host allowlist contains an invalid hostname.');
            }
            $normalized[] = $host;
        }
        $normalized = array_values(array_unique($normalized));
        sort($normalized);
        if ($normalized === []) {
            throw new RuntimeException('HTTP host allowlist cannot be empty.');
        }
        $this->allowedHosts = $normalized;
    }

    public function assertAllowedUrl(string $url): void
    {
        if ($url === '' || strlen($url) > 4096 || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            throw new HttpRequestException('HTTP request URL is invalid.');
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            throw new HttpRequestException('HTTP request URL is invalid.');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $port = isset($parts['port']) ? (int) $parts['port'] : 443;
        if ($scheme !== 'https' || $host === '' || !in_array($host, $this->allowedHosts, true)) {
            throw new HttpRequestException('HTTP request URL is outside the official-source allowlist.');
        }
        if ($port !== 443) {
            throw new HttpRequestException('Official-source HTTPS requests must use port 443.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new HttpRequestException('Credentials are not permitted in HTTP request URLs.');
        }
        if (isset($parts['fragment'])) {
            throw new HttpRequestException('Fragments are not permitted in server-side HTTP request URLs.');
        }
    }

    /** @param array<string, string> $headers @return list<string> */
    public function headerLines(array $headers): array
    {
        if (count($headers) > 50) {
            throw new HttpRequestException('HTTP request contains too many headers.');
        }

        $lines = [];
        foreach ($headers as $name => $value) {
            if (!is_string($name) || !is_string($value)) {
                throw new HttpRequestException('HTTP request headers must use string names and values.');
            }
            if ($name === '' || strlen($name) > 128 || preg_match("/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/D", $name) !== 1) {
                throw new HttpRequestException('HTTP request header name is invalid.');
            }
            if (strlen($value) > 8192 || preg_match('/[\x00\r\n]/', $value) === 1) {
                throw new HttpRequestException('HTTP request header value is invalid.');
            }
            $lines[] = $name . ': ' . trim($value);
        }

        return $lines;
    }
}
