<?php

declare(strict_types=1);

namespace HalalPulse\Http;

use RuntimeException;

final class CurlHttpClient implements HttpClient
{
    private readonly HttpRequestPolicy $requestPolicy;

    /** @param list<string> $allowedHosts */
    public function __construct(
        array $allowedHosts,
        private readonly int $timeoutSeconds,
        private readonly string $userAgent,
        private readonly int $maxResponseBytes = 8_388_608,
        private readonly int $maxHeaderBytes = 65_536,
    ) {
        $this->requestPolicy = new HttpRequestPolicy($allowedHosts);

        if ($this->timeoutSeconds < 1 || $this->timeoutSeconds > 120) {
            throw new RuntimeException('HTTP timeout must be between 1 and 120 seconds.');
        }
        if ($this->maxResponseBytes < 1 || $this->maxResponseBytes > 67_108_864) {
            throw new RuntimeException('HTTP response limit must be between 1 byte and 64 MiB.');
        }
        if ($this->maxHeaderBytes < 1 || $this->maxHeaderBytes > 131_072) {
            throw new RuntimeException('HTTP response-header limit must be between 1 byte and 128 KiB.');
        }
        if ($this->userAgent === '' || strlen($this->userAgent) > 256 || preg_match('/[\x00-\x08\x0a-\x1f\x7f]/', $this->userAgent) === 1) {
            throw new RuntimeException('HTTP user agent is invalid.');
        }
    }

    public function get(string $url, array $headers = []): HttpResponse
    {
        $this->requestPolicy->assertAllowedUrl($url);
        $headerLines = $this->requestPolicy->headerLines($headers);

        if (!extension_loaded('curl')) {
            throw new HttpRequestException('The PHP cURL extension is required.');
        }

        $handle = curl_init($url);
        if ($handle === false) {
            throw new HttpRequestException('Unable to initialize the HTTP request.');
        }

        $body = '';
        $responseHeaders = [];
        $bodyTooLarge = false;
        $headersTooLarge = false;
        $headerBytes = 0;

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeoutSeconds),
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => function ($curl, string $line) use (&$responseHeaders, &$headersTooLarge, &$headerBytes): int {
                $length = strlen($line);
                $headerBytes += $length;
                if ($headerBytes > $this->maxHeaderBytes) {
                    $headersTooLarge = true;
                    return 0;
                }

                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $name = strtolower(trim($parts[0]));
                    $value = trim($parts[1]);
                    if ($name !== '') {
                        $responseHeaders[$name] ??= [];
                        $responseHeaders[$name][] = $value;
                    }
                }

                return $length;
            },
            CURLOPT_WRITEFUNCTION => function ($curl, string $chunk) use (&$body, &$bodyTooLarge): int {
                if (strlen($body) + strlen($chunk) > $this->maxResponseBytes) {
                    $bodyTooLarge = true;
                    return 0;
                }

                $body .= $chunk;

                return strlen($chunk);
            },
        ]);

        try {
            $succeeded = curl_exec($handle);
            $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

            if ($headersTooLarge) {
                throw new HttpRequestException('Official-source response headers exceeded the configured size limit.', $statusCode ?: null);
            }
            if ($bodyTooLarge) {
                throw new HttpRequestException('Official-source response exceeded the configured size limit.', $statusCode ?: null);
            }
            if ($succeeded === false) {
                throw new HttpRequestException('Official-source request failed: ' . curl_error($handle), $statusCode ?: null);
            }
            if ($statusCode < 200 || $statusCode >= 300) {
                throw new HttpRequestException("Official source returned HTTP {$statusCode}.", $statusCode);
            }

            return new HttpResponse($statusCode, $responseHeaders, $body);
        } finally {
            curl_close($handle);
        }
    }
}
