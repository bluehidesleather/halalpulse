<?php

declare(strict_types=1);

namespace HalalPulse\Government;

use HalalPulse\Config;
use HalalPulse\Http\CurlHttpClient;
use InvalidArgumentException;

final class GovernmentHttpClientFactory
{
    public static function fromConfig(Config $config): CurlHttpClient
    {
        $hosts = $config->get('government_polling.allowed_hosts', []);
        if (!is_array($hosts)) {
            throw new InvalidArgumentException('government_polling.allowed_hosts must be an array.');
        }
        $hosts = array_values(array_filter($hosts, static fn (mixed $host): bool => is_string($host) && trim($host) !== ''));

        return new CurlHttpClient(
            allowedHosts: $hosts,
            timeoutSeconds: (int) $config->get('government_polling.request_timeout_seconds', 20),
            userAgent: (string) $config->get('government_polling.user_agent', 'HalalPulse/0.9'),
            maxResponseBytes: (int) $config->get('government_polling.max_response_bytes', 8_388_608),
            maxHeaderBytes: (int) $config->get('government_polling.max_header_bytes', 65_536),
        );
    }
}
