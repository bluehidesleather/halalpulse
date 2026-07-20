SET NAMES utf8mb4;
SET time_zone = '+05:30';

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email VARCHAR(191) NOT NULL,
    display_name VARCHAR(100) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin') NOT NULL DEFAULT 'admin',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    identity_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    ip_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    was_successful TINYINT(1) NOT NULL DEFAULT 0,
    attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_login_attempts_identity_time (identity_hash, attempted_at),
    KEY idx_login_attempts_ip_time (ip_hash, attempted_at),
    KEY idx_login_attempts_cleanup (attempted_at),
    CONSTRAINT fk_login_attempts_user FOREIGN KEY (user_id) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS companies (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    exchange ENUM('NSE', 'BSE') NOT NULL,
    symbol VARCHAR(64) NOT NULL,
    isin VARCHAR(16) NULL,
    company_name VARCHAR(255) NOT NULL,
    sector VARCHAR(150) NULL,
    industry VARCHAR(150) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    first_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_companies_exchange_symbol (exchange, symbol),
    KEY idx_companies_isin (isin),
    KEY idx_companies_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS filings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    exchange ENUM('NSE', 'BSE') NOT NULL,
    source_id VARCHAR(191) NOT NULL,
    category VARCHAR(255) NOT NULL DEFAULT '',
    subject TEXT NOT NULL,
    announced_at DATETIME NOT NULL,
    attachment_url TEXT NULL,
    payload_hash CHAR(64) NOT NULL,
    raw_payload JSON NOT NULL,
    is_quarterly_result_candidate TINYINT(1) NOT NULL DEFAULT 0,
    classifier_confidence TINYINT UNSIGNED NOT NULL DEFAULT 0,
    classifier_reason VARCHAR(500) NOT NULL DEFAULT '',
    processing_status ENUM('detected', 'queued', 'processed', 'rejected', 'failed') NOT NULL DEFAULT 'detected',
    detected_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_filings_exchange_source (exchange, source_id),
    KEY idx_filings_announced_at (announced_at),
    KEY idx_filings_candidate_status (is_quarterly_result_candidate, processing_status),
    KEY idx_filings_company (company_id),
    CONSTRAINT fk_filings_company FOREIGN KEY (company_id) REFERENCES companies (id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS source_checkpoints (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    exchange ENUM('NSE', 'BSE') NOT NULL,
    last_successful_announcement_at DATETIME NULL,
    last_successful_poll_at DATETIME NULL,
    cursor_payload JSON NULL,
    consecutive_failures INT UNSIGNED NOT NULL DEFAULT 0,
    last_error TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_source_checkpoints_exchange (exchange)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS filing_documents (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    filing_id BIGINT UNSIGNED NOT NULL,
    source_url TEXT NOT NULL,
    storage_path VARCHAR(500) NULL,
    mime_type VARCHAR(100) NULL,
    file_size_bytes BIGINT UNSIGNED NULL,
    sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    download_status ENUM('pending', 'downloading', 'downloaded', 'failed', 'unsupported') NOT NULL DEFAULT 'pending',
    extraction_status ENUM('pending', 'extracted', 'manual_review', 'failed') NOT NULL DEFAULT 'pending',
    extracted_text LONGTEXT NULL,
    download_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    last_error TEXT NULL,
    downloaded_at DATETIME NULL,
    extracted_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_filing_documents_filing (filing_id),
    KEY idx_filing_documents_download (download_status, download_attempts),
    KEY idx_filing_documents_extraction (extraction_status),
    KEY idx_filing_documents_sha256 (sha256),
    CONSTRAINT fk_filing_documents_filing FOREIGN KEY (filing_id) REFERENCES filings (id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS document_metric_candidates (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    document_id BIGINT UNSIGNED NOT NULL,
    metric_key ENUM('revenue', 'total_income', 'ebitda', 'profit_before_tax', 'net_profit', 'eps') NOT NULL,
    statement_scope ENUM('standalone', 'consolidated', 'unknown') NOT NULL DEFAULT 'unknown',
    period_label VARCHAR(100) NOT NULL DEFAULT '',
    current_value DECIMAL(24, 6) NOT NULL,
    comparison_value DECIMAL(24, 6) NULL,
    currency CHAR(3) NULL,
    scale_label ENUM('one', 'thousand', 'lakh', 'million', 'crore') NOT NULL DEFAULT 'one',
    confidence TINYINT UNSIGNED NOT NULL DEFAULT 0,
    evidence_snippet VARCHAR(1000) NOT NULL,
    review_status ENUM('pending', 'accepted', 'rejected') NOT NULL DEFAULT 'pending',
    reviewed_by_user_id BIGINT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_metric_candidates_document (document_id),
    KEY idx_metric_candidates_review (review_status, metric_key),
    CONSTRAINT fk_metric_candidates_document FOREIGN KEY (document_id) REFERENCES filing_documents (id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_metric_candidates_reviewer FOREIGN KEY (reviewed_by_user_id) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS poll_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    exchange ENUM('NSE', 'BSE') NOT NULL,
    status ENUM('running', 'succeeded', 'failed', 'skipped') NOT NULL DEFAULT 'running',
    started_at DATETIME NOT NULL,
    finished_at DATETIME NULL,
    records_seen INT UNSIGNED NOT NULL DEFAULT 0,
    records_inserted INT UNSIGNED NOT NULL DEFAULT 0,
    candidates_detected INT UNSIGNED NOT NULL DEFAULT 0,
    http_status SMALLINT UNSIGNED NULL,
    duration_ms INT UNSIGNED NULL,
    error_message TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_poll_runs_exchange_started (exchange, started_at),
    KEY idx_poll_runs_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sharia_policies (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    version VARCHAR(64) NOT NULL,
    name VARCHAR(191) NOT NULL,
    authority_name VARCHAR(191) NOT NULL,
    authority_standard VARCHAR(100) NOT NULL,
    authority_reference_url VARCHAR(500) NOT NULL,
    effective_date DATE NOT NULL,
    verified_by VARCHAR(191) NOT NULL,
    verification_note VARCHAR(1000) NOT NULL,
    ratios_json JSON NOT NULL,
    policy_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 0,
    activated_by_user_id BIGINT UNSIGNED NULL,
    activated_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sharia_policies_version (version),
    UNIQUE KEY uq_sharia_policies_hash (policy_hash),
    KEY idx_sharia_policies_active (is_active, activated_at),
    CONSTRAINT fk_sharia_policies_activator FOREIGN KEY (activated_by_user_id) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS company_sharia_activity_reviews (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    activity_status ENUM('pending', 'permissible', 'prohibited', 'mixed') NOT NULL DEFAULT 'pending',
    activity_description VARCHAR(1000) NOT NULL,
    evidence_source_url VARCHAR(1000) NULL,
    evidence_note VARCHAR(1000) NOT NULL,
    reviewed_by_user_id BIGINT UNSIGNED NOT NULL,
    reviewed_at DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_activity_reviews_company (company_id, id),
    KEY idx_activity_reviews_status (activity_status, reviewed_at),
    CONSTRAINT fk_activity_reviews_company FOREIGN KEY (company_id) REFERENCES companies (id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_activity_reviews_reviewer FOREIGN KEY (reviewed_by_user_id) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sharia_financial_inputs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    period_end DATE NOT NULL,
    metric_key VARCHAR(64) NOT NULL,
    value DECIMAL(24, 6) NOT NULL,
    currency CHAR(3) NOT NULL,
    scale_label ENUM('one', 'thousand', 'lakh', 'million', 'crore') NOT NULL DEFAULT 'one',
    source_document_id BIGINT UNSIGNED NULL,
    evidence_note VARCHAR(1000) NOT NULL,
    evidence_status ENUM('current', 'superseded') NOT NULL DEFAULT 'current',
    accepted_by_user_id BIGINT UNSIGNED NOT NULL,
    accepted_at DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_sharia_inputs_company_period (company_id, period_end, evidence_status),
    KEY idx_sharia_inputs_metric (metric_key, evidence_status),
    KEY idx_sharia_inputs_document (source_document_id),
    CONSTRAINT fk_sharia_inputs_company FOREIGN KEY (company_id) REFERENCES companies (id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_sharia_inputs_document FOREIGN KEY (source_document_id) REFERENCES filing_documents (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_sharia_inputs_acceptor FOREIGN KEY (accepted_by_user_id) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sharia_screenings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    policy_id BIGINT UNSIGNED NOT NULL,
    period_end DATE NOT NULL,
    status ENUM('passed', 'failed', 'insufficient') NOT NULL,
    compliance_rank TINYINT UNSIGNED NULL,
    activity_status ENUM('pending', 'permissible', 'prohibited', 'mixed') NOT NULL,
    ratio_results JSON NOT NULL,
    reasons JSON NOT NULL,
    input_snapshot JSON NOT NULL,
    computed_by_user_id BIGINT UNSIGNED NOT NULL,
    computed_at DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_sharia_screenings_company (company_id, id),
    KEY idx_sharia_screenings_status (status, computed_at),
    KEY idx_sharia_screenings_policy (policy_id, computed_at),
    CONSTRAINT fk_sharia_screenings_company FOREIGN KEY (company_id) REFERENCES companies (id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_sharia_screenings_policy FOREIGN KEY (policy_id) REFERENCES sharia_policies (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_sharia_screenings_user FOREIGN KEY (computed_by_user_id) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT chk_sharia_screenings_result_rank CHECK (
        (status = 'passed' AND compliance_rank BETWEEN 1 AND 5)
        OR (status IN ('failed', 'insufficient') AND compliance_rank IS NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS multibagger_methodologies (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    version VARCHAR(64) NOT NULL,
    name VARCHAR(191) NOT NULL,
    effective_date DATE NOT NULL,
    verified_by VARCHAR(191) NOT NULL,
    verification_note VARCHAR(1000) NOT NULL,
    definition_json JSON NOT NULL,
    methodology_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 0,
    activated_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_multibagger_methodologies_version (version),
    UNIQUE KEY uq_multibagger_methodologies_hash (methodology_hash),
    KEY idx_multibagger_methodologies_active (is_active, activated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS multibagger_factor_reviews (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    period_end DATE NOT NULL,
    factor_key VARCHAR(64) NOT NULL,
    grade TINYINT UNSIGNED NOT NULL,
    evidence_note VARCHAR(1000) NOT NULL,
    evidence_source_url VARCHAR(1000) NULL,
    source_document_id BIGINT UNSIGNED NULL,
    government_tailwind_review_id BIGINT UNSIGNED NULL,
    review_status ENUM('current', 'superseded') NOT NULL DEFAULT 'current',
    reviewed_by_user_id BIGINT UNSIGNED NOT NULL,
    reviewed_at DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_multibagger_factor_current (company_id, period_end, review_status, factor_key),
    KEY idx_multibagger_factor_document (source_document_id),
    KEY idx_multibagger_factor_government_review (government_tailwind_review_id),
    CONSTRAINT fk_multibagger_factor_company FOREIGN KEY (company_id) REFERENCES companies (id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_multibagger_factor_document FOREIGN KEY (source_document_id) REFERENCES filing_documents (id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_multibagger_factor_government_review FOREIGN KEY (government_tailwind_review_id) REFERENCES government_tailwind_reviews (id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_multibagger_factor_user FOREIGN KEY (reviewed_by_user_id) REFERENCES users (id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT chk_multibagger_factor_grade CHECK (grade BETWEEN 1 AND 10)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS multibagger_valuation_reviews (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    period_end DATE NOT NULL,
    currency CHAR(3) NOT NULL,
    eps DECIMAL(24, 6) NOT NULL,
    book_value_per_share DECIMAL(24, 6) NOT NULL,
    dcf_value_per_share DECIMAL(24, 6) NOT NULL,
    current_price DECIMAL(24, 6) NOT NULL,
    dcf_assumptions_note VARCHAR(1000) NOT NULL,
    evidence_note VARCHAR(1000) NOT NULL,
    evidence_source_url VARCHAR(1000) NOT NULL,
    source_document_id BIGINT UNSIGNED NULL,
    review_status ENUM('current', 'superseded') NOT NULL DEFAULT 'current',
    reviewed_by_user_id BIGINT UNSIGNED NOT NULL,
    reviewed_at DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_multibagger_valuation_current (company_id, period_end, review_status),
    KEY idx_multibagger_valuation_document (source_document_id),
    CONSTRAINT fk_multibagger_valuation_company FOREIGN KEY (company_id) REFERENCES companies (id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_multibagger_valuation_document FOREIGN KEY (source_document_id) REFERENCES filing_documents (id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_multibagger_valuation_user FOREIGN KEY (reviewed_by_user_id) REFERENCES users (id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS multibagger_risk_reviews (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    period_end DATE NOT NULL,
    market_cap_crore DECIMAL(24, 6) NOT NULL,
    red_flags JSON NOT NULL,
    green_flags JSON NOT NULL,
    evidence_note VARCHAR(1000) NOT NULL,
    evidence_source_url VARCHAR(1000) NOT NULL,
    review_status ENUM('current', 'superseded') NOT NULL DEFAULT 'current',
    reviewed_by_user_id BIGINT UNSIGNED NOT NULL,
    reviewed_at DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_multibagger_risk_current (company_id, period_end, review_status),
    CONSTRAINT fk_multibagger_risk_company FOREIGN KEY (company_id) REFERENCES companies (id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_multibagger_risk_user FOREIGN KEY (reviewed_by_user_id) REFERENCES users (id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS multibagger_scores (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    methodology_id BIGINT UNSIGNED NOT NULL,
    sharia_screening_id BIGINT UNSIGNED NOT NULL,
    period_end DATE NOT NULL,
    status ENUM('scored', 'insufficient') NOT NULL,
    final_score TINYINT UNSIGNED NULL,
    weighted_score DECIMAL(6, 3) NULL,
    market_cap_category ENUM('large', 'mid', 'small', 'micro', 'nano', 'unknown') NOT NULL DEFAULT 'unknown',
    undervalued_by_both TINYINT(1) NOT NULL DEFAULT 0,
    alert_eligible TINYINT(1) NOT NULL DEFAULT 0,
    factor_results JSON NOT NULL,
    reasons JSON NOT NULL,
    valuation_snapshot JSON NOT NULL,
    risk_snapshot JSON NOT NULL,
    computed_by_user_id BIGINT UNSIGNED NOT NULL,
    computed_at DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_multibagger_scores_company (company_id, id),
    KEY idx_multibagger_scores_alert (alert_eligible, computed_at),
    KEY idx_multibagger_scores_methodology (methodology_id, computed_at),
    CONSTRAINT fk_multibagger_scores_company FOREIGN KEY (company_id) REFERENCES companies (id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_multibagger_scores_methodology FOREIGN KEY (methodology_id) REFERENCES multibagger_methodologies (id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_multibagger_scores_sharia FOREIGN KEY (sharia_screening_id) REFERENCES sharia_screenings (id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_multibagger_scores_user FOREIGN KEY (computed_by_user_id) REFERENCES users (id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT chk_multibagger_score_result CHECK (
        (status = 'scored' AND final_score BETWEEN 1 AND 10 AND weighted_score IS NOT NULL)
        OR (status = 'insufficient' AND final_score IS NULL AND weighted_score IS NULL AND alert_eligible = 0)
    ),
    CONSTRAINT chk_multibagger_alert_gate CHECK (alert_eligible = 0 OR (status = 'scored' AND final_score <= 4))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS alert_deliveries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    score_id BIGINT UNSIGNED NOT NULL,
    recipient_id BIGINT UNSIGNED NULL,
    channel ENUM('telegram','whatsapp') NOT NULL DEFAULT 'telegram',
    recipient_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status ENUM('reserved', 'accepted', 'failed', 'unknown') NOT NULL DEFAULT 'reserved',
    provider_message_id VARCHAR(191) NULL,
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
    UNIQUE KEY uq_alert_delivery_provider_message (channel, recipient_hash, provider_message_id),
    KEY idx_alert_delivery_status (status, reserved_at),
    KEY idx_alert_delivery_recipient (recipient_id, created_at),
    CONSTRAINT fk_alert_delivery_score FOREIGN KEY (score_id) REFERENCES multibagger_scores (id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_alert_delivery_recipient FOREIGN KEY (recipient_id) REFERENCES alert_recipients (id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS alert_delivery_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    delivery_id BIGINT UNSIGNED NOT NULL,
    attempt_number TINYINT UNSIGNED NOT NULL,
    result ENUM('running', 'accepted', 'failed', 'unknown') NOT NULL DEFAULT 'running',
    provider_message_id VARCHAR(191) NULL,
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

INSERT INTO source_checkpoints (exchange)
VALUES ('NSE'), ('BSE')
ON DUPLICATE KEY UPDATE exchange = VALUES(exchange);

INSERT INTO government_source_checkpoints(source)
VALUES ('PIB'), ('SEBI'), ('RBI'), ('MCA'), ('BUDGET')
ON DUPLICATE KEY UPDATE source=VALUES(source);
