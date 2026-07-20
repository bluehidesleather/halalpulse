SET NAMES utf8mb4;
SET time_zone = '+05:30';

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

CREATE TABLE IF NOT EXISTS multibagger_factor_reviews (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    period_end DATE NOT NULL,
    factor_key VARCHAR(64) NOT NULL,
    grade TINYINT UNSIGNED NOT NULL,
    evidence_note VARCHAR(1000) NOT NULL,
    evidence_source_url VARCHAR(1000) NULL,
    source_document_id BIGINT UNSIGNED NULL,
    review_status ENUM('current', 'superseded') NOT NULL DEFAULT 'current',
    reviewed_by_user_id BIGINT UNSIGNED NOT NULL,
    reviewed_at DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_multibagger_factor_current (company_id, period_end, review_status, factor_key),
    KEY idx_multibagger_factor_document (source_document_id),
    CONSTRAINT fk_multibagger_factor_company FOREIGN KEY (company_id) REFERENCES companies (id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_multibagger_factor_document FOREIGN KEY (source_document_id) REFERENCES filing_documents (id) ON UPDATE CASCADE ON DELETE SET NULL,
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
