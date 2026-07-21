<?php

declare(strict_types=1);

namespace HalalPulse\Alerts;

use JsonException;

final readonly class TelegramRequestPolicy
{
    /** @var list<string> */
    private const ALLOWED_METHODS = ['getUpdates', 'sendMessage'];

    public function __construct(
        private string $botToken,
        private int $maxRequestBytes,
    ) {
        if (preg_match('/^[1-9][0-9]{5,15}:[A-Za-z0-9_-]{30,100}$/D', $this->botToken) !== 1) {
            throw new TelegramApiException('Telegram bot token format is invalid.', outcomeKnown: true);
        }
        if ($this->maxRequestBytes < 1024 || $this->maxRequestBytes > 65_536) {
            throw new TelegramApiException('Telegram request-size limit is invalid.', outcomeKnown: true);
        }
    }

    public function endpoint(string $method): string
    {
        if (!in_array($method, self::ALLOWED_METHODS, true)) {
            throw new TelegramApiException('Telegram API method is not allowed.', outcomeKnown: true);
        }

        return 'https://api.telegram.org/bot' . $this->botToken . '/' . $method;
    }

    /** @param array<string, mixed> $parameters */
    public function encodeParameters(array $parameters): string
    {
        try {
            $body = json_encode($parameters, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            throw new TelegramApiException('Telegram request parameters could not be encoded.', outcomeKnown: true);
        }

        if (strlen($body) < 2 || strlen($body) > $this->maxRequestBytes) {
            throw new TelegramApiException('Telegram request body exceeded the configured size limit.', outcomeKnown: true);
        }

        return $body;
    }

    public function safeProviderDescription(string $description, int $maximumCharacters = 500): string
    {
        $maximumCharacters = max(50, min(1000, $maximumCharacters));
        $description = str_replace($this->botToken, '[redacted-token]', $description);
        $description = preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', '', $description) ?? '';
        $description = preg_replace('/[\r\n\t]+/', ' ', $description) ?? '';
        $description = trim(preg_replace('/ {2,}/', ' ', $description) ?? '');

        return mb_substr($description === '' ? 'Telegram returned an unspecified provider error.' : $description, 0, $maximumCharacters);
    }
}
