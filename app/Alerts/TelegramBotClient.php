<?php

declare(strict_types=1);

namespace HalalPulse\Alerts;

use JsonException;

final readonly class TelegramBotClient
{
    private TelegramRequestPolicy $requestPolicy;

    public function __construct(private AlertConfiguration $configuration)
    {
        $this->requestPolicy = new TelegramRequestPolicy(
            botToken: $configuration->botToken,
            maxRequestBytes: $configuration->maxRequestBytes,
        );
    }

    public function send(string $chatId, string $body): ProviderMessageResult
    {
        $this->configuration->assertReady();
        if (preg_match('/^-?[1-9][0-9]{0,18}$/D', $chatId) !== 1) {
            throw new TelegramApiException('Telegram recipient address is invalid.', outcomeKnown: true);
        }
        if (mb_strlen($body) < 10 || mb_strlen($body) > 4096) {
            throw new TelegramApiException('Telegram message body must contain 10 to 4,096 characters.', outcomeKnown: true);
        }
        $payload = $this->request('sendMessage', [
            'chat_id' => $chatId,
            'text' => $body,
            'disable_web_page_preview' => true,
        ], sendOutcomeMayBeUnknown: true);
        $result = $payload['result'] ?? null;
        if (!is_array($result)) {
            throw new TelegramApiException('Telegram success response did not contain a result object.', outcomeKnown: false);
        }
        $messageId = $result['message_id'] ?? null;
        $responseChatId = $result['chat']['id'] ?? null;
        if ((!is_int($messageId) && !(is_string($messageId) && ctype_digit($messageId))) || (int) $messageId < 1) {
            throw new TelegramApiException('Telegram success response did not contain a valid message ID.', outcomeKnown: false);
        }
        if ((string) $responseChatId !== $chatId) {
            throw new TelegramApiException('Telegram success response identified an unexpected recipient.', outcomeKnown: false);
        }

        return new ProviderMessageResult((string) $messageId, 'sent');
    }

    /** @return list<array{chat_id:string,type:string,label:string,last_update_id:int}> */
    public function discoverChats(): array
    {
        $this->configuration->assertTransportReady();
        $payload = $this->request('getUpdates', ['limit' => 100, 'timeout' => 0], sendOutcomeMayBeUnknown: false);
        $updates = $payload['result'] ?? null;
        if (!is_array($updates)) {
            throw new TelegramApiException('Telegram getUpdates response did not contain an update list.', outcomeKnown: true);
        }
        $chats = [];
        foreach ($updates as $update) {
            if (!is_array($update)) {
                continue;
            }
            $message = $update['message'] ?? $update['channel_post'] ?? $update['edited_message'] ?? null;
            $chat = is_array($message) ? ($message['chat'] ?? null) : null;
            if (!is_array($chat) || !isset($chat['id'])) {
                continue;
            }
            $chatId = (string) $chat['id'];
            if (preg_match('/^-?[1-9][0-9]{0,18}$/D', $chatId) !== 1) {
                continue;
            }
            $labelParts = array_filter([
                is_string($chat['first_name'] ?? null) ? trim($chat['first_name']) : '',
                is_string($chat['last_name'] ?? null) ? trim($chat['last_name']) : '',
                is_string($chat['title'] ?? null) ? trim($chat['title']) : '',
            ]);
            $chats[$chatId] = [
                'chat_id' => $chatId,
                'type' => is_string($chat['type'] ?? null) ? mb_substr($chat['type'], 0, 32) : 'unknown',
                'label' => mb_substr(implode(' ', $labelParts) ?: 'Telegram recipient', 0, 100),
                'last_update_id' => is_int($update['update_id'] ?? null) && $update['update_id'] >= 0 ? $update['update_id'] : 0,
            ];
        }

        return array_values($chats);
    }

    /** @param array<string,mixed> $parameters @return array<string,mixed> */
    private function request(string $method, array $parameters, bool $sendOutcomeMayBeUnknown): array
    {
        if (!extension_loaded('curl')) {
            throw new TelegramApiException('The PHP cURL extension is required for Telegram delivery.', outcomeKnown: true);
        }
        $this->configuration->assertTransportReady();
        $url = $this->requestPolicy->endpoint($method);
        $requestBody = $this->requestPolicy->encodeParameters($parameters);
        $handle = curl_init($url);
        if ($handle === false) {
            throw new TelegramApiException('Unable to initialize the Telegram request.', outcomeKnown: true);
        }

        $responseBody = '';
        $responseHeaders = [];
        $bodyTooLarge = false;
        $headersTooLarge = false;
        $headerBytes = 0;
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $requestBody,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json'],
            CURLOPT_CONNECTTIMEOUT => min(10, $this->configuration->timeoutSeconds),
            CURLOPT_TIMEOUT => $this->configuration->timeoutSeconds,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => function ($curl, string $line) use (&$responseHeaders, &$headersTooLarge, &$headerBytes): int {
                $length = strlen($line);
                $headerBytes += $length;
                if ($headerBytes > $this->configuration->maxHeaderBytes) {
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
            CURLOPT_WRITEFUNCTION => function ($curl, string $chunk) use (&$responseBody, &$bodyTooLarge): int {
                if (strlen($responseBody) + strlen($chunk) > $this->configuration->maxResponseBytes) {
                    $bodyTooLarge = true;
                    return 0;
                }
                $responseBody .= $chunk;

                return strlen($chunk);
            },
        ]);

        try {
            $succeeded = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            if ($headersTooLarge) {
                throw new TelegramApiException('Telegram response headers exceeded the configured size limit.', $status ?: null, outcomeKnown: !$sendOutcomeMayBeUnknown);
            }
            if ($bodyTooLarge) {
                throw new TelegramApiException('Telegram response exceeded the configured size limit.', $status ?: null, outcomeKnown: !$sendOutcomeMayBeUnknown);
            }
            if ($succeeded === false) {
                throw new TelegramApiException('Telegram request did not return a complete response.', $status ?: null, outcomeKnown: !$sendOutcomeMayBeUnknown);
            }
            $contentTypes = $responseHeaders['content-type'] ?? [];
            $jsonContentType = $contentTypes === [];
            foreach ($contentTypes as $contentType) {
                if (str_contains(strtolower($contentType), 'application/json')) {
                    $jsonContentType = true;
                    break;
                }
            }
            if (!$jsonContentType) {
                throw new TelegramApiException('Telegram returned an unexpected content type.', $status ?: null, outcomeKnown: $status >= 400 || !$sendOutcomeMayBeUnknown);
            }
            try {
                $payload = json_decode($responseBody, true, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new TelegramApiException('Telegram returned invalid JSON.', $status ?: null, outcomeKnown: $status >= 400 || !$sendOutcomeMayBeUnknown);
            }
            if (!is_array($payload)) {
                throw new TelegramApiException('Telegram returned an invalid response object.', $status ?: null, outcomeKnown: $status >= 400 || !$sendOutcomeMayBeUnknown);
            }
            if ($status < 200 || $status >= 300 || ($payload['ok'] ?? false) !== true) {
                $providerCode = isset($payload['error_code']) ? (string) $payload['error_code'] : null;
                $description = is_string($payload['description'] ?? null)
                    ? $this->requestPolicy->safeProviderDescription((string) $payload['description'])
                    : "Telegram returned HTTP {$status}.";
                throw new TelegramApiException($description, $status, $providerCode, true);
            }

            return $payload;
        } finally {
            curl_close($handle);
        }
    }
}
