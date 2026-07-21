SET NAMES utf8mb4;
SET time_zone = '+05:30';

ALTER TABLE sharia_financial_inputs
    MODIFY COLUMN value DECIMAL(36, 6) NOT NULL,
    ADD COLUMN source_integrated_item_id BIGINT UNSIGNED NULL AFTER source_document_id,
    ADD COLUMN source_fact_name VARCHAR(191) NULL AFTER source_integrated_item_id,
    ADD KEY idx_sharia_inputs_xbrl_item (source_integrated_item_id),
    ADD CONSTRAINT fk_sharia_inputs_xbrl_item
        FOREIGN KEY (source_integrated_item_id) REFERENCES nse_integrated_feed_items (id)
        ON UPDATE CASCADE ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS sharia_input_candidates (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    integrated_item_id BIGINT UNSIGNED NOT NULL,
    period_end DATE NOT NULL,
    metric_key VARCHAR(64) NOT NULL,
    candidate_value DECIMAL(36, 6) NOT NULL,
    currency CHAR(3) NOT NULL,
    scale_label ENUM('one', 'thousand', 'lakh', 'million', 'crore') NOT NULL DEFAULT 'one',
    source_fact_name VARCHAR(191) NOT NULL,
    source_context_ref VARCHAR(191) NOT NULL,
    mapping_confidence TINYINT UNSIGNED NOT NULL,
    mapping_reason VARCHAR(500) NOT NULL,
    review_status ENUM('pending', 'accepted', 'rejected') NOT NULL DEFAULT 'pending',
    reviewed_by_user_id BIGINT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sharia_candidate_source (
        integrated_item_id,
        metric_key,
        source_fact_name,
        source_context_ref
    ),
    KEY idx_sharia_candidates_company (company_id, period_end, review_status),
    KEY idx_sharia_candidates_review (review_status, mapping_confidence, created_at),
    CONSTRAINT fk_sharia_candidates_company FOREIGN KEY (company_id) REFERENCES companies (id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_sharia_candidates_item FOREIGN KEY (integrated_item_id) REFERENCES nse_integrated_feed_items (id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_sharia_candidates_reviewer FOREIGN KEY (reviewed_by_user_id) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT chk_sharia_candidate_confidence CHECK (mapping_confidence BETWEEN 1 AND 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
