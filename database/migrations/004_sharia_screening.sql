SET NAMES utf8mb4;
SET time_zone = '+05:30';

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
