<?php

declare(strict_types=1);

namespace HalalPulse\Alerts;

use InvalidArgumentException;
use PDO;

final readonly class AlertRecipientRepository
{
    public function __construct(
        private PDO $pdo,
        private AlertRecipientCrypto $crypto,
    ) {
    }

    public function registerTelegram(string $chatId, string $label, int $confirmedByUserId): int
    {
        $label = trim($label);
        if ($label === '' || mb_strlen($label) > 100) {
            throw new InvalidArgumentException('Recipient label must contain 1 to 100 characters.');
        }
        if ($confirmedByUserId < 1) {
            throw new InvalidArgumentException('A valid administrator is required to confirm the recipient.');
        }
        $protected = $this->crypto->encryptTelegramChatId($chatId);
        $statement = $this->pdo->prepare(
            <<<'SQL'
            INSERT INTO alert_recipients(
                channel,label,address_ciphertext,address_nonce,address_tag,recipient_hash,is_active,
                consented_at,last_verified_at,confirmed_by_user_id
            ) VALUES(
                'telegram',:label,:ciphertext,:nonce,:tag,:recipient_hash,1,
                CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,:user_id
            )
            ON DUPLICATE KEY UPDATE
                label=VALUES(label),address_ciphertext=VALUES(address_ciphertext),address_nonce=VALUES(address_nonce),
                address_tag=VALUES(address_tag),is_active=1,last_verified_at=CURRENT_TIMESTAMP,
                confirmed_by_user_id=VALUES(confirmed_by_user_id),updated_at=CURRENT_TIMESTAMP
            SQL
        );
        $statement->bindValue(':label', $label);
        $statement->bindValue(':ciphertext', $protected['ciphertext'], PDO::PARAM_LOB);
        $statement->bindValue(':nonce', $protected['nonce'], PDO::PARAM_LOB);
        $statement->bindValue(':tag', $protected['tag'], PDO::PARAM_LOB);
        $statement->bindValue(':recipient_hash', $protected['recipient_hash']);
        $statement->bindValue(':user_id', $confirmedByUserId, PDO::PARAM_INT);
        $statement->execute();

        $lookup = $this->pdo->prepare("SELECT id FROM alert_recipients WHERE channel='telegram' AND recipient_hash=:recipient_hash LIMIT 1");
        $lookup->execute(['recipient_hash' => $protected['recipient_hash']]);
        $id = $lookup->fetchColumn();
        if ($id === false) {
            throw new InvalidArgumentException('Telegram recipient could not be reloaded after registration.');
        }

        return (int) $id;
    }

    public function deactivate(int $recipientId): void
    {
        if ($recipientId < 1) {
            throw new InvalidArgumentException('Invalid alert recipient.');
        }
        $statement = $this->pdo->prepare("UPDATE alert_recipients SET is_active=0,updated_at=CURRENT_TIMESTAMP WHERE id=:id AND channel='telegram'");
        $statement->execute(['id' => $recipientId]);
        if ($statement->rowCount() !== 1) {
            throw new InvalidArgumentException('Active Telegram recipient was not found.');
        }
    }

    /** @return list<AlertRecipient> */
    public function activeTelegram(int $limit): array
    {
        $limit = max(1, min(25, $limit));
        $rows = $this->pdo->query(
            "SELECT id,channel,label,address_ciphertext,address_nonce,address_tag,recipient_hash FROM alert_recipients WHERE channel='telegram' AND is_active=1 ORDER BY id LIMIT " . $limit
        )->fetchAll();
        $recipients = [];
        foreach ($rows as $row) {
            $recipients[] = $this->hydrate($row);
        }

        return $recipients;
    }

    public function activeTelegramById(int $recipientId): ?AlertRecipient
    {
        $statement = $this->pdo->prepare(
            "SELECT id,channel,label,address_ciphertext,address_nonce,address_tag,recipient_hash FROM alert_recipients WHERE id=:id AND channel='telegram' AND is_active=1 LIMIT 1"
        );
        $statement->execute(['id' => $recipientId]);
        $row = $statement->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    /** @return list<array<string,mixed>> */
    public function listTelegram(): array
    {
        return $this->pdo->query(
            "SELECT ar.id,ar.channel,ar.label,ar.is_active,ar.consented_at,ar.last_verified_at,ar.created_at,u.display_name AS confirmed_by_name FROM alert_recipients ar LEFT JOIN users u ON u.id=ar.confirmed_by_user_id WHERE ar.channel='telegram' ORDER BY ar.is_active DESC,ar.id"
        )->fetchAll();
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): AlertRecipient
    {
        $recipientHash = (string) ($row['recipient_hash'] ?? '');
        $address = $this->crypto->decryptTelegramChatId(
            (string) ($row['address_ciphertext'] ?? ''),
            (string) ($row['address_nonce'] ?? ''),
            (string) ($row['address_tag'] ?? ''),
            $recipientHash,
        );

        return new AlertRecipient(
            id: (int) $row['id'],
            channel: (string) $row['channel'],
            label: (string) $row['label'],
            address: $address,
            recipientHash: $recipientHash,
        );
    }
}
