SET NAMES utf8mb4;
SET time_zone = '+05:30';

ALTER TABLE users
    ADD COLUMN auth_version BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER password_hash,
    ADD KEY idx_users_auth_version (id, auth_version);
