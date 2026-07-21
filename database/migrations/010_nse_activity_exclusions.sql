SET NAMES utf8mb4;
SET time_zone = '+05:30';

ALTER TABLE nse_integrated_feed_items
    MODIFY COLUMN status ENUM('pending', 'processing', 'processed', 'failed', 'excluded')
        NOT NULL DEFAULT 'pending',
    ADD COLUMN exclusion_reason VARCHAR(500) NULL AFTER last_error,
    ADD COLUMN excluded_at DATETIME NULL AFTER exclusion_reason,
    ADD KEY idx_nse_integrated_excluded (status, excluded_at);

UPDATE nse_integrated_feed_items
SET status = 'excluded',
    next_attempt_at = NULL,
    last_error = NULL,
    exclusion_reason = 'Conventional banking taxonomy excluded from Sharia screening.',
    excluded_at = COALESCE(excluded_at, CURRENT_TIMESTAMP),
    updated_at = CURRENT_TIMESTAMP
WHERE LEFT(source_filename, 26) = 'INTEGRATED_FILING_BANKING_'
  AND status IN ('pending', 'processing', 'failed');
