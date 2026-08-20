CREATE TABLE IF NOT EXISTS "{{prefix}}flm_notification_outbox" (
  "id" BIGSERIAL NOT NULL,
  "event_key" CHAR(64) NOT NULL,
  "link_id" BIGINT NULL,
  "event_type" VARCHAR(24) NOT NULL,
  "channel" VARCHAR(24) NOT NULL,
  "subject" VARCHAR(255) NOT NULL,
  "message" TEXT NOT NULL,
  "payload_json" TEXT NOT NULL,
  "status" VARCHAR(16) NOT NULL DEFAULT 'pending',
  "attempts" INTEGER NOT NULL DEFAULT 0,
  "available_at" BIGINT NOT NULL,
  "lease_token" CHAR(32) NULL,
  "lease_until" BIGINT NULL,
  "last_error" VARCHAR(500) NULL,
  "created_at" BIGINT NOT NULL,
  "sent_at" BIGINT NULL,
  PRIMARY KEY ("id"),
  UNIQUE ("event_key", "channel")
);
CREATE INDEX IF NOT EXISTS "{{prefix}}flm_notification_schedule"
  ON "{{prefix}}flm_notification_outbox" ("status", "available_at", "lease_until");
CREATE INDEX IF NOT EXISTS "{{prefix}}flm_notification_link_created"
  ON "{{prefix}}flm_notification_outbox" ("link_id", "created_at");
