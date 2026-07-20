SET NAMES utf8mb4;
SET time_zone = '+05:30';

CREATE TABLE IF NOT EXISTS government_announcements (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    source ENUM('PIB', 'SEBI', 'RBI', 'MCA', 'BUDGET') NOT NULL,
    source_id VARCHAR(191) NOT NULL,
    category VARCHAR(255) NOT NULL DEFAULT '',
    title VARCHAR(1000) NOT NULL,
    summary TEXT NOT NULL,
    published_at DATETIME NOT NULL,
    official_url VARCHAR(1000) NOT NULL,
    payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    raw_payload JSON NOT NULL,
    classifier_sector VARCHAR(150) NULL,
    classifier_impact ENUM('tailwind', 'headwind', 'neutral', 'unclassified') NOT NULL DEFAULT 'unclassified',
    classifier_confidence TINYINT UNSIGNED NOT NULL DEFAULT 0,
    classifier_reason VARCHAR(1000) NOT NULL,
    detected_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_government_announcement_source (source, source_id),
    KEY idx_government_announcement_published (published_at, source),
    KEY idx_government_announcement_classifier (classifier_impact, classifier_sector),
    CONSTRAINT chk_government_classifier_confidence CHECK (classifier_confidence BETWEEN 0 AND 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS government_tailwind_reviews (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    announcement_id BIGINT UNSIGNED NOT NULL,
    sector VARCHAR(150) NOT NULL,
    impact ENUM('strong_tailwind', 'moderate_tailwind', 'neutral', 'headwind', 'not_relevant') NOT NULL,
    rationale VARCHAR(1000) NOT NULL,
    review_status ENUM('current', 'superseded') NOT NULL DEFAULT 'current',
    reviewed_by_user_id BIGINT UNSIGNED NOT NULL,
    reviewed_at DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_government_review_current (announcement_id, review_status),
    KEY idx_government_review_tailwind (review_status, impact, sector),
    CONSTRAINT fk_government_review_announcement FOREIGN KEY (announcement_id) REFERENCES government_announcements (id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_government_review_user FOREIGN KEY (reviewed_by_user_id) REFERENCES users (id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS government_source_checkpoints (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    source ENUM('PIB', 'SEBI', 'RBI', 'MCA', 'BUDGET') NOT NULL,
    last_successful_announcement_at DATETIME NULL,
    last_successful_poll_at DATETIME NULL,
    consecutive_failures INT UNSIGNED NOT NULL DEFAULT 0,
    last_error TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_government_checkpoint_source (source)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS government_poll_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    source ENUM('PIB', 'SEBI', 'RBI', 'MCA', 'BUDGET') NOT NULL,
    status ENUM('running', 'succeeded', 'failed', 'skipped') NOT NULL DEFAULT 'running',
    started_at DATETIME NOT NULL,
    finished_at DATETIME NULL,
    records_seen INT UNSIGNED NOT NULL DEFAULT 0,
    records_inserted INT UNSIGNED NOT NULL DEFAULT 0,
    candidates_detected INT UNSIGNED NOT NULL DEFAULT 0,
    duration_ms INT UNSIGNED NULL,
    error_message TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_government_poll_source_started (source, started_at),
    KEY idx_government_poll_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE multibagger_factor_reviews
    ADD COLUMN government_tailwind_review_id BIGINT UNSIGNED NULL AFTER source_document_id,
    ADD KEY idx_multibagger_factor_government_review (government_tailwind_review_id),
    ADD CONSTRAINT fk_multibagger_factor_government_review FOREIGN KEY (government_tailwind_review_id)
        REFERENCES government_tailwind_reviews (id) ON UPDATE CASCADE ON DELETE SET NULL;

INSERT INTO government_source_checkpoints(source)
VALUES ('PIB'), ('SEBI'), ('RBI'), ('MCA'), ('BUDGET')
ON DUPLICATE KEY UPDATE source=VALUES(source);
