<?php

declare(strict_types=1);

namespace HalalPulse\Alerts;

use InvalidArgumentException;
use RuntimeException;

final readonly class AlertRecipientCrypto
{
    private string $key;

    public function __construct(string $appKey)
    {
        if (strlen($appKey) < 32) {
            throw new InvalidArgumentException('Application key is too short for alert recipient protection.');
        }
        if (!extension_loaded('openssl')) {
            throw new RuntimeException('The OpenSSL extension is required to protect Telegram recipient addresses.');
        }
        $this->key = hash('sha256', "halalpulse:alert-recipient\0" . $appKey, true);
    }

    public function normalizeTelegramChatId(string $chatId): string
    {
        $chatId = trim($chatId);
        if (preg_match('/^-?[1-9][0-9]{0,18}$/D', $chatId) !== 1) {
            throw new InvalidArgumentException('Telegram chat ID must be a non-zero integer with at most 19 digits.');
        }

        return $chatId;
    }

    public function recipientHash(string $chatId): string
    {
        return hash_hmac('sha256', "telegram\0" . $this->normalizeTelegramChatId($chatId), $this->key);
    }

    /** @return array{ciphertext:string,nonce:string,tag:string,recipient_hash:string} */
    public function encryptTelegramChatId(string $chatId): array
    {
        $chatId = $this->normalizeTelegramChatId($chatId);
        $recipientHash = $this->recipientHash($chatId);
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $chatId,
            'aes-256-gcm',
            $this->key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $this->aad($recipientHash),
            16,
        );
        if (!is_string($ciphertext) || strlen($tag) !== 16) {
            throw new RuntimeException('Unable to encrypt the Telegram recipient address.');
        }

        return [
            'ciphertext' => $ciphertext,
            'nonce' => $nonce,
            'tag' => $tag,
            'recipient_hash' => $recipientHash,
        ];
    }

    public function decryptTelegramChatId(string $ciphertext, string $nonce, string $tag, string $recipientHash): string
    {
        if (strlen($nonce) !== 12 || strlen($tag) !== 16 || preg_match('/^[0-9a-f]{64}$/D', $recipientHash) !== 1) {
            throw new RuntimeException('Stored Telegram recipient protection metadata is invalid.');
        }
        $plaintext = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            $this->key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $this->aad($recipientHash),
        );
        if (!is_string($plaintext)) {
            throw new RuntimeException('Unable to decrypt the Telegram recipient address.');
        }
        $chatId = $this->normalizeTelegramChatId($plaintext);
        if (!hash_equals($recipientHash, $this->recipientHash($chatId))) {
            throw new RuntimeException('Stored Telegram recipient identity failed its integrity check.');
        }

        return $chatId;
    }

    private function aad(string $recipientHash): string
    {
        return 'halalpulse:telegram:v1:' . $recipientHash;
    }
}
