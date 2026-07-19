<?php

declare(strict_types=1);

namespace HalalPulse\Http;

use HalalPulse\Config;
use InvalidArgumentException;

final class HttpClientFactory
{
    public static function fromConfig(Config $config): CurlHttpClient
    {
        $hosts = $config->get('polling.allowed_hosts', []);
        if (!is_array($hosts)) {
            throw new InvalidArgumentException('polling.allowed_hosts must be an array.');
        }

        $hosts = array_values(array_filter(
            $hosts,
            static fn (mixed $host): bool => is_string($host) && trim($host) !== '',
        ));

        return new CurlHttpClient(
            allowedHosts: $hosts,
            timeoutSeconds: (int) $config->get('polling.request_timeout_seconds', 20),
            userAgent: (string) $config->get('polling.user_agent', 'HalalPulse/0.2'),
            maxResponseBytes: (int) $config->get('polling.max_response_bytes', 8_388_608),
        );
    }
}
