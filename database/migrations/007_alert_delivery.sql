SET NAMES utf8mb4;
SET time_zone = '+05:30';

CREATE TABLE IF NOT EXISTS alert_deliveries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    score_id BIGINT UNSIGNED NOT NULL,
    channel ENUM('whatsapp') NOT NULL DEFAULT 'whatsapp',
    recipient_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status ENUM('reserved', 'accepted', 'failed', 'unknown') NOT NULL DEFAULT 'reserved',
    provider_message_sid VARCHAR(34) NULL,
    provider_status VARCHAR(32) NULL,
    message_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
    reserved_at DATETIME NOT NULL,
    submitted_at DATETIME NULL,
    last_attempt_at DATETIME NULL,
    error_code VARCHAR(64) NULL,
    error_message VARCHAR(1000) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_alert_delivery_candidate (score_id, channel, recipient_hash),
    UNIQUE KEY uq_alert_delivery_provider_sid (provider_message_sid),
    KEY idx_alert_delivery_status (status, reserved_at),
    CONSTRAINT fk_alert_delivery_score FOREIGN KEY (score_id) REFERENCES multibagger_scores (id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS alert_delivery_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    delivery_id BIGINT UNSIGNED NOT NULL,
    attempt_number TINYINT UNSIGNED NOT NULL,
    result ENUM('running', 'accepted', 'failed', 'unknown') NOT NULL DEFAULT 'running',
    provider_message_sid VARCHAR(34) NULL,
    provider_status VARCHAR(32) NULL,
    error_code VARCHAR(64) NULL,
    error_message VARCHAR(1000) NULL,
    started_at DATETIME NOT NULL,
    finished_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_alert_attempt_number (delivery_id, attempt_number),
    KEY idx_alert_attempt_result (result, started_at),
    CONSTRAINT fk_alert_attempt_delivery FOREIGN KEY (delivery_id) REFERENCES alert_deliveries (id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
