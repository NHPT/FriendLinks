CREATE TABLE IF NOT EXISTS `{{prefix}}flm_categories` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(120) NOT NULL,
  `slug` VARCHAR(120) NOT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` BIGINT NOT NULL,
  `updated_at` BIGINT NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `flm_categories_slug` (`slug`),
  KEY `flm_categories_sort` (`enabled`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{{prefix}}flm_links` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `category_id` BIGINT UNSIGNED NULL,
  `name` VARCHAR(150) NOT NULL,
  `url` TEXT NOT NULL,
  `normalized_url` TEXT NOT NULL,
  `url_hash` CHAR(64) NOT NULL,
  `description` VARCHAR(500) NOT NULL DEFAULT '',
  `logo_url` TEXT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `visibility` VARCHAR(16) NOT NULL DEFAULT 'published',
  `check_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` BIGINT NOT NULL,
  `updated_at` BIGINT NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `flm_links_url_hash` (`url_hash`),
  KEY `flm_links_display` (`visibility`, `category_id`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{{prefix}}flm_current_status` (
  `link_id` BIGINT UNSIGNED NOT NULL,
  `overall_state` VARCHAR(24) NOT NULL DEFAULT 'pending',
  `reason_code` VARCHAR(64) NULL,
  `http_state` VARCHAR(32) NULL,
  `http_code` INT NULL,
  `response_time_ms` INT NULL,
  `final_url` TEXT NULL,
  `dns_state` VARCHAR(32) NULL,
  `tls_state` VARCHAR(32) NULL,
  `cert_not_after` BIGINT NULL,
  `domain_state` VARCHAR(32) NULL,
  `domain_expires_at` BIGINT NULL,
  `availability_consecutive_failures` INT NOT NULL DEFAULT 0,
  `checked_at` BIGINT NULL,
  `dns_checked_at` BIGINT NULL,
  `http_checked_at` BIGINT NULL,
  `tls_checked_at` BIGINT NULL,
  `domain_checked_at` BIGINT NULL,
  `dns_next_check_at` BIGINT NOT NULL DEFAULT 0,
  `http_next_check_at` BIGINT NOT NULL DEFAULT 0,
  `tls_next_check_at` BIGINT NOT NULL DEFAULT 0,
  `domain_next_check_at` BIGINT NOT NULL DEFAULT 0,
  `last_success_at` BIGINT NULL,
  `last_failure_at` BIGINT NULL,
  `state_changed_at` BIGINT NULL,
  `next_check_at` BIGINT NOT NULL DEFAULT 0,
  `lease_token` CHAR(32) NULL,
  `lease_until` BIGINT NULL,
  `details_json` LONGTEXT NULL,
  PRIMARY KEY (`link_id`),
  KEY `flm_status_schedule` (`next_check_at`, `lease_until`),
  KEY `flm_status_overall` (`overall_state`, `checked_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{{prefix}}flm_check_history` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `link_id` BIGINT UNSIGNED NOT NULL,
  `run_id` CHAR(32) NOT NULL,
  `overall_state` VARCHAR(24) NOT NULL,
  `reason_code` VARCHAR(64) NULL,
  `http_code` INT NULL,
  `response_time_ms` INT NULL,
  `started_at` BIGINT NOT NULL,
  `finished_at` BIGINT NOT NULL,
  `details_json` LONGTEXT NULL,
  PRIMARY KEY (`id`),
  KEY `flm_history_link_started` (`link_id`, `started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{{prefix}}flm_runs` (
  `run_id` CHAR(32) NOT NULL,
  `mode` VARCHAR(16) NOT NULL,
  `status` VARCHAR(16) NOT NULL,
  `started_at` BIGINT NOT NULL,
  `heartbeat_at` BIGINT NOT NULL,
  `finished_at` BIGINT NULL,
  `claimed_count` INT NOT NULL DEFAULT 0,
  `completed_count` INT NOT NULL DEFAULT 0,
  `failed_count` INT NOT NULL DEFAULT 0,
  `error_summary` VARCHAR(500) NULL,
  PRIMARY KEY (`run_id`),
  KEY `flm_runs_started_status` (`started_at`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{{prefix}}flm_cache` (
  `cache_key` CHAR(64) NOT NULL,
  `namespace` VARCHAR(32) NOT NULL,
  `payload` LONGTEXT NOT NULL,
  `expires_at` BIGINT NOT NULL,
  `updated_at` BIGINT NOT NULL,
  PRIMARY KEY (`cache_key`),
  KEY `flm_cache_expiry` (`namespace`, `expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
