CREATE TABLE IF NOT EXISTS "{{prefix}}flm_categories" (
  "id" BIGSERIAL NOT NULL,
  "name" VARCHAR(120) NOT NULL,
  "slug" VARCHAR(120) NOT NULL,
  "sort_order" INTEGER NOT NULL DEFAULT 0,
  "enabled" SMALLINT NOT NULL DEFAULT 1,
  "created_at" BIGINT NOT NULL,
  "updated_at" BIGINT NOT NULL,
  PRIMARY KEY ("id"),
  UNIQUE ("slug")
);
CREATE INDEX IF NOT EXISTS "{{prefix}}flm_categories_sort" ON "{{prefix}}flm_categories" ("enabled", "sort_order");

CREATE TABLE IF NOT EXISTS "{{prefix}}flm_links" (
  "id" BIGSERIAL NOT NULL,
  "category_id" BIGINT NULL,
  "name" VARCHAR(150) NOT NULL,
  "url" TEXT NOT NULL,
  "normalized_url" TEXT NOT NULL,
  "url_hash" CHAR(64) NOT NULL,
  "description" VARCHAR(500) NOT NULL DEFAULT '',
  "logo_url" TEXT NULL,
  "sort_order" INTEGER NOT NULL DEFAULT 0,
  "visibility" VARCHAR(16) NOT NULL DEFAULT 'published',
  "check_enabled" SMALLINT NOT NULL DEFAULT 1,
  "created_at" BIGINT NOT NULL,
  "updated_at" BIGINT NOT NULL,
  PRIMARY KEY ("id"),
  UNIQUE ("url_hash")
);
CREATE INDEX IF NOT EXISTS "{{prefix}}flm_links_display" ON "{{prefix}}flm_links" ("visibility", "category_id", "sort_order");

CREATE TABLE IF NOT EXISTS "{{prefix}}flm_current_status" (
  "link_id" BIGINT NOT NULL,
  "overall_state" VARCHAR(24) NOT NULL DEFAULT 'pending',
  "reason_code" VARCHAR(64) NULL,
  "http_state" VARCHAR(32) NULL,
  "http_code" INTEGER NULL,
  "response_time_ms" INTEGER NULL,
  "final_url" TEXT NULL,
  "dns_state" VARCHAR(32) NULL,
  "tls_state" VARCHAR(32) NULL,
  "cert_not_after" BIGINT NULL,
  "domain_state" VARCHAR(32) NULL,
  "domain_expires_at" BIGINT NULL,
  "availability_consecutive_failures" INTEGER NOT NULL DEFAULT 0,
  "checked_at" BIGINT NULL,
  "dns_checked_at" BIGINT NULL,
  "http_checked_at" BIGINT NULL,
  "tls_checked_at" BIGINT NULL,
  "domain_checked_at" BIGINT NULL,
  "dns_next_check_at" BIGINT NOT NULL DEFAULT 0,
  "http_next_check_at" BIGINT NOT NULL DEFAULT 0,
  "tls_next_check_at" BIGINT NOT NULL DEFAULT 0,
  "domain_next_check_at" BIGINT NOT NULL DEFAULT 0,
  "last_success_at" BIGINT NULL,
  "last_failure_at" BIGINT NULL,
  "state_changed_at" BIGINT NULL,
  "next_check_at" BIGINT NOT NULL DEFAULT 0,
  "lease_token" CHAR(32) NULL,
  "lease_until" BIGINT NULL,
  "details_json" TEXT NULL,
  PRIMARY KEY ("link_id")
);
CREATE INDEX IF NOT EXISTS "{{prefix}}flm_status_schedule" ON "{{prefix}}flm_current_status" ("next_check_at", "lease_until");
CREATE INDEX IF NOT EXISTS "{{prefix}}flm_status_overall" ON "{{prefix}}flm_current_status" ("overall_state", "checked_at");

CREATE TABLE IF NOT EXISTS "{{prefix}}flm_check_history" (
  "id" BIGSERIAL NOT NULL,
  "link_id" BIGINT NOT NULL,
  "run_id" CHAR(32) NOT NULL,
  "overall_state" VARCHAR(24) NOT NULL,
  "reason_code" VARCHAR(64) NULL,
  "http_code" INTEGER NULL,
  "response_time_ms" INTEGER NULL,
  "started_at" BIGINT NOT NULL,
  "finished_at" BIGINT NOT NULL,
  "details_json" TEXT NULL,
  PRIMARY KEY ("id")
);
CREATE INDEX IF NOT EXISTS "{{prefix}}flm_history_link_started" ON "{{prefix}}flm_check_history" ("link_id", "started_at");

CREATE TABLE IF NOT EXISTS "{{prefix}}flm_runs" (
  "run_id" CHAR(32) NOT NULL,
  "mode" VARCHAR(16) NOT NULL,
  "status" VARCHAR(16) NOT NULL,
  "started_at" BIGINT NOT NULL,
  "heartbeat_at" BIGINT NOT NULL,
  "finished_at" BIGINT NULL,
  "claimed_count" INTEGER NOT NULL DEFAULT 0,
  "completed_count" INTEGER NOT NULL DEFAULT 0,
  "failed_count" INTEGER NOT NULL DEFAULT 0,
  "error_summary" VARCHAR(500) NULL,
  PRIMARY KEY ("run_id")
);
CREATE INDEX IF NOT EXISTS "{{prefix}}flm_runs_started_status" ON "{{prefix}}flm_runs" ("started_at", "status");

CREATE TABLE IF NOT EXISTS "{{prefix}}flm_cache" (
  "cache_key" CHAR(64) NOT NULL,
  "namespace" VARCHAR(32) NOT NULL,
  "payload" TEXT NOT NULL,
  "expires_at" BIGINT NOT NULL,
  "updated_at" BIGINT NOT NULL,
  PRIMARY KEY ("cache_key")
);
CREATE INDEX IF NOT EXISTS "{{prefix}}flm_cache_expiry" ON "{{prefix}}flm_cache" ("namespace", "expires_at");
