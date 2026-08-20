CREATE TABLE IF NOT EXISTS "{{prefix}}flm_categories" (
  "id" INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
  "name" VARCHAR(120) NOT NULL,
  "slug" VARCHAR(120) NOT NULL,
  "sort_order" INTEGER NOT NULL DEFAULT 0,
  "enabled" INTEGER NOT NULL DEFAULT 1,
  "created_at" INTEGER NOT NULL,
  "updated_at" INTEGER NOT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS "{{prefix}}flm_categories_slug" ON "{{prefix}}flm_categories" ("slug");
CREATE INDEX IF NOT EXISTS "{{prefix}}flm_categories_sort" ON "{{prefix}}flm_categories" ("enabled", "sort_order");

CREATE TABLE IF NOT EXISTS "{{prefix}}flm_links" (
  "id" INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
  "category_id" INTEGER NULL,
  "name" VARCHAR(150) NOT NULL,
  "url" TEXT NOT NULL,
  "normalized_url" TEXT NOT NULL,
  "url_hash" CHAR(64) NOT NULL,
  "description" VARCHAR(500) NOT NULL DEFAULT '',
  "logo_url" TEXT NULL,
  "sort_order" INTEGER NOT NULL DEFAULT 0,
  "visibility" VARCHAR(16) NOT NULL DEFAULT 'published',
  "check_enabled" INTEGER NOT NULL DEFAULT 1,
  "created_at" INTEGER NOT NULL,
  "updated_at" INTEGER NOT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS "{{prefix}}flm_links_url_hash" ON "{{prefix}}flm_links" ("url_hash");
CREATE INDEX IF NOT EXISTS "{{prefix}}flm_links_display" ON "{{prefix}}flm_links" ("visibility", "category_id", "sort_order");

CREATE TABLE IF NOT EXISTS "{{prefix}}flm_current_status" (
  "link_id" INTEGER NOT NULL PRIMARY KEY,
  "overall_state" VARCHAR(24) NOT NULL DEFAULT 'pending',
  "reason_code" VARCHAR(64) NULL,
  "http_state" VARCHAR(32) NULL,
  "http_code" INTEGER NULL,
  "response_time_ms" INTEGER NULL,
  "final_url" TEXT NULL,
  "dns_state" VARCHAR(32) NULL,
  "tls_state" VARCHAR(32) NULL,
  "cert_not_after" INTEGER NULL,
  "domain_state" VARCHAR(32) NULL,
  "domain_expires_at" INTEGER NULL,
  "availability_consecutive_failures" INTEGER NOT NULL DEFAULT 0,
  "checked_at" INTEGER NULL,
  "dns_checked_at" INTEGER NULL,
  "http_checked_at" INTEGER NULL,
  "tls_checked_at" INTEGER NULL,
  "domain_checked_at" INTEGER NULL,
  "dns_next_check_at" INTEGER NOT NULL DEFAULT 0,
  "http_next_check_at" INTEGER NOT NULL DEFAULT 0,
  "tls_next_check_at" INTEGER NOT NULL DEFAULT 0,
  "domain_next_check_at" INTEGER NOT NULL DEFAULT 0,
  "last_success_at" INTEGER NULL,
  "last_failure_at" INTEGER NULL,
  "state_changed_at" INTEGER NULL,
  "next_check_at" INTEGER NOT NULL DEFAULT 0,
  "lease_token" CHAR(32) NULL,
  "lease_until" INTEGER NULL,
  "details_json" TEXT NULL
);
CREATE INDEX IF NOT EXISTS "{{prefix}}flm_status_schedule" ON "{{prefix}}flm_current_status" ("next_check_at", "lease_until");
CREATE INDEX IF NOT EXISTS "{{prefix}}flm_status_overall" ON "{{prefix}}flm_current_status" ("overall_state", "checked_at");

CREATE TABLE IF NOT EXISTS "{{prefix}}flm_check_history" (
  "id" INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
  "link_id" INTEGER NOT NULL,
  "run_id" CHAR(32) NOT NULL,
  "overall_state" VARCHAR(24) NOT NULL,
  "reason_code" VARCHAR(64) NULL,
  "http_code" INTEGER NULL,
  "response_time_ms" INTEGER NULL,
  "started_at" INTEGER NOT NULL,
  "finished_at" INTEGER NOT NULL,
  "details_json" TEXT NULL
);
CREATE INDEX IF NOT EXISTS "{{prefix}}flm_history_link_started" ON "{{prefix}}flm_check_history" ("link_id", "started_at");

CREATE TABLE IF NOT EXISTS "{{prefix}}flm_runs" (
  "run_id" CHAR(32) NOT NULL PRIMARY KEY,
  "mode" VARCHAR(16) NOT NULL,
  "status" VARCHAR(16) NOT NULL,
  "started_at" INTEGER NOT NULL,
  "heartbeat_at" INTEGER NOT NULL,
  "finished_at" INTEGER NULL,
  "claimed_count" INTEGER NOT NULL DEFAULT 0,
  "completed_count" INTEGER NOT NULL DEFAULT 0,
  "failed_count" INTEGER NOT NULL DEFAULT 0,
  "error_summary" VARCHAR(500) NULL
);
CREATE INDEX IF NOT EXISTS "{{prefix}}flm_runs_started_status" ON "{{prefix}}flm_runs" ("started_at", "status");

CREATE TABLE IF NOT EXISTS "{{prefix}}flm_cache" (
  "cache_key" CHAR(64) NOT NULL PRIMARY KEY,
  "namespace" VARCHAR(32) NOT NULL,
  "payload" TEXT NOT NULL,
  "expires_at" INTEGER NOT NULL,
  "updated_at" INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS "{{prefix}}flm_cache_expiry" ON "{{prefix}}flm_cache" ("namespace", "expires_at");
