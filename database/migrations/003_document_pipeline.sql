SET NAMES utf8mb4;
SET time_zone = '+05:30';

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
