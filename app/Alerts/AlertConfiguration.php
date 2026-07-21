<?php

declare(strict_types=1);

namespace HalalPulse\Alerts;

use HalalPulse\Config;
use InvalidArgumentException;

final readonly class AlertConfiguration
{
    public function __construct(
        public bool $enabled,
        public string $channel,
        public int $batchSize,
        public int $recipientLimit,
        public string $appBaseUrl,
        public string $botToken,
        public int $timeoutSeconds,
        public int $maxResponseBytes,
        public int $maxRequestBytes = 32_768,
        public int $maxHeaderBytes = 65_536,
    ) {
    }

    public static function fromConfig(Config $config): self
    {
        return new self(
            enabled: $config->get('alerts.enabled', false) === true,
            channel: strtolower(trim((string) $config->get('alerts.channel', 'telegram'))),
            batchSize: (int) $config->get('alerts.batch_size', 1),
            recipientLimit: (int) $config->get('alerts.recipient_limit', 25),
            appBaseUrl: rtrim((string) $config->get('alerts.app_base_url', ''), '/'),
            botToken: trim((string) $config->get('alerts.telegram.bot_token', '')),
            timeoutSeconds: (int) $config->get('alerts.telegram.request_timeout_seconds', 20),
            maxResponseBytes: (int) $config->get('alerts.telegram.max_response_bytes', 1_048_576),
            maxRequestBytes: (int) $config->get('alerts.telegram.max_request_bytes', 32_768),
            maxHeaderBytes: (int) $config->get('alerts.telegram.max_header_bytes', 65_536),
        );
    }

    public function assertReady(): void
    {
        if (!$this->enabled) {
            throw new InvalidArgumentException('Alert delivery is disabled.');
        }
        $this->assertTransportReady();
    }

    public function assertTransportReady(): void
    {
        if ($this->channel !== 'telegram') {
            throw new InvalidArgumentException('Only the Telegram alert channel is supported.');
        }
        if ($this->batchSize < 1 || $this->batchSize > 5) {
            throw new InvalidArgumentException('Alert batch size must be between 1 and 5.');
        }
        if ($this->recipientLimit < 1 || $this->recipientLimit > 25) {
            throw new InvalidArgumentException('Telegram recipient limit must be between 1 and 25 on shared hosting.');
        }
        if (preg_match('/^[1-9][0-9]{5,15}:[A-Za-z0-9_-]{30,100}$/D', $this->botToken) !== 1) {
            throw new InvalidArgumentException('Telegram bot token format is invalid.');
        }
        if ($this->appBaseUrl === '' || strlen($this->appBaseUrl) > 2048 || preg_match('/[\x00-\x20\x7f]/', $this->appBaseUrl) === 1) {
            throw new InvalidArgumentException('Alert application base URL is invalid.');
        }
        $parts = parse_url($this->appBaseUrl);
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
        $path = is_array($parts) ? (string) ($parts['path'] ?? '') : '';
        if (!is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || $host === ''
            || filter_var($host, FILTER_VALIDATE_IP) !== false
            || preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))+$/D', $host) !== 1
            || ($path !== '' && $path !== '/')
            || isset($parts['query'])
            || isset($parts['fragment'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['port'])) {
            throw new InvalidArgumentException('Alert application base URL must be a public HTTPS origin without credentials, a custom port, path, query, or fragment.');
        }
        if ($this->timeoutSeconds < 1 || $this->timeoutSeconds > 60
            || $this->maxRequestBytes < 1024 || $this->maxRequestBytes > 65_536
            || $this->maxResponseBytes < 1024 || $this->maxResponseBytes > 4_194_304
            || $this->maxHeaderBytes < 1024 || $this->maxHeaderBytes > 131_072) {
            throw new InvalidArgumentException('Telegram request and response limits are invalid.');
        }
    }
}
