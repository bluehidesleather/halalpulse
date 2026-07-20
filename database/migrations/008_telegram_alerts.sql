SET NAMES utf8mb4;
SET time_zone = '+05:30';

CREATE TABLE IF NOT EXISTS alert_recipients (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    channel ENUM('telegram') NOT NULL DEFAULT 'telegram',
    label VARCHAR(100) NOT NULL,
    address_ciphertext VARBINARY(255) NOT NULL,
    address_nonce BINARY(12) NOT NULL,
    address_tag BINARY(16) NOT NULL,
    recipient_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    consented_at DATETIME NOT NULL,
    last_verified_at DATETIME NOT NULL,
    confirmed_by_user_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_alert_recipient_identity (channel, recipient_hash),
    KEY idx_alert_recipient_active (channel, is_active),
    CONSTRAINT fk_alert_recipient_user FOREIGN KEY (confirmed_by_user_id) REFERENCES users (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE alert_deliveries
    MODIFY COLUMN channel ENUM('telegram','whatsapp') NOT NULL DEFAULT 'telegram',
    ADD COLUMN recipient_id BIGINT UNSIGNED NULL AFTER score_id,
    ADD KEY idx_alert_delivery_recipient (recipient_id, created_at),
    ADD CONSTRAINT fk_alert_delivery_recipient FOREIGN KEY (recipient_id) REFERENCES alert_recipients (id) ON UPDATE CASCADE ON DELETE RESTRICT;

ALTER TABLE alert_deliveries
    CHANGE COLUMN provider_message_sid provider_message_id VARCHAR(191) NULL;

ALTER TABLE alert_deliveries
    DROP INDEX uq_alert_delivery_provider_sid,
    ADD UNIQUE KEY uq_alert_delivery_provider_message (channel, recipient_hash, provider_message_id);

ALTER TABLE alert_delivery_attempts
    CHANGE COLUMN provider_message_sid provider_message_id VARCHAR(191) NULL;
