-- Phase 15: Cron Run Log, Request Load Minute, and Slow Queries Storage
-- Strict-mode safe, DEFAULT-safe, fully indexed.

CREATE TABLE IF NOT EXISTS `rise_cron_run_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `job` VARCHAR(64) NOT NULL,
  `trigger_source` VARCHAR(32) NOT NULL DEFAULT 'scheduler',
  `started_at` DATETIME NOT NULL,
  `duration_ms` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
  `status` ENUM('ok', 'skipped_by_gate', 'failed') NOT NULL DEFAULT 'ok',
  `gate_reason` VARCHAR(255) DEFAULT NULL,
  `items_processed` INT UNSIGNED NOT NULL DEFAULT 0,
  `memory_peak` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `error_message` TEXT DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_job_started` (`job`, `started_at`),
  KEY `idx_status` (`status`),
  KEY `idx_started_at` (`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rise_request_load_minute` (
  `minute` DATETIME NOT NULL,
  `category` VARCHAR(32) NOT NULL,
  `endpoint` VARCHAR(128) NOT NULL,
  `requests` INT UNSIGNED NOT NULL DEFAULT 0,
  `total_ms` DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
  `max_ms` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
  `db_ms` DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
  `query_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `memory_peak` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `sample_user_ids` VARCHAR(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`minute`, `category`, `endpoint`),
  KEY `idx_minute_cat` (`minute`, `category`),
  KEY `idx_category` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rise_slow_queries` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `logged_at` DATETIME NOT NULL,
  `duration_ms` DECIMAL(10, 2) NOT NULL,
  `endpoint` VARCHAR(128) NOT NULL DEFAULT '',
  `user_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `query_fingerprint` TEXT NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_logged_at` (`logged_at`),
  KEY `idx_duration` (`duration_ms`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
