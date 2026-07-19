<?php

declare(strict_types=1);

namespace HalalPulse\Http;

use RuntimeException;

final class CurlHttpClient implements HttpClient
{
    /** @param list<string> $allowedHosts */
    public function __construct(
        private readonly array $allowedHosts,
        private readonly int $timeoutSeconds,
        private readonly string $userAgent,
        private readonly int $maxResponseBytes = 8_388_608,
    ) {
        if ($this->allowedHosts === []) {
            throw new RuntimeException('HTTP host allowlist cannot be empty.');
        }

        if ($this->timeoutSeconds < 1 || $this->maxResponseBytes < 1) {
            throw new RuntimeException('HTTP timeout and response limit must be positive.');
        }
    }

    public function get(string $url, array $headers = []): HttpResponse
    {
        $this->assertAllowedUrl($url);

        if (!extension_loaded('curl')) {
            throw new HttpRequestException('The PHP cURL extension is required.');
        }

        $handle = curl_init($url);
        if ($handle === false) {
            throw new HttpRequestException('Unable to initialize the HTTP request.');
        }

        $body = '';
        $responseHeaders = [];
        $tooLarge = false;
        $headerLines = [];

        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeoutSeconds),
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                $length = strlen($line);
                $parts = explode(':', $line, 2);

                if (count($parts) === 2) {
                    $name = strtolower(trim($parts[0]));
                    $value = trim($parts[1]);
                    $responseHeaders[$name] ??= [];
                    $responseHeaders[$name][] = $value;
                }

                return $length;
            },
            CURLOPT_WRITEFUNCTION => function ($curl, string $chunk) use (&$body, &$tooLarge): int {
                if (strlen($body) + strlen($chunk) > $this->maxResponseBytes) {
                    $tooLarge = true;
                    return 0;
                }

                $body .= $chunk;

                return strlen($chunk);
            },
        ]);

        try {
            $succeeded = curl_exec($handle);
            $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

            if ($tooLarge) {
                throw new HttpRequestException('Exchange response exceeded the configured size limit.', $statusCode ?: null);
            }

            if ($succeeded === false) {
                throw new HttpRequestException('Exchange request failed: ' . curl_error($handle), $statusCode ?: null);
            }

            if ($statusCode < 200 || $statusCode >= 300) {
                throw new HttpRequestException("Exchange returned HTTP {$statusCode}.", $statusCode);
            }

            return new HttpResponse($statusCode, $responseHeaders, $body);
        } finally {
            curl_close($handle);
        }
    }

    private function assertAllowedUrl(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $allowed = array_map(static fn (string $item): string => strtolower($item), $this->allowedHosts);

        if ($scheme !== 'https' || $host === '' || !in_array($host, $allowed, true)) {
            throw new HttpRequestException('HTTP request URL is outside the official-source allowlist.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new HttpRequestException('Credentials are not permitted in HTTP request URLs.');
        }
    }
}
