-- =============================================================================
-- alter-2026-04-29-agent-activity.sql
-- Apply on the live database to add the per-agent activity log.
-- Idempotent: every statement uses IF NOT EXISTS so a repeat run is a no-op.
--
-- Companion change in agents (immunity-demo):
--   - agents/src/db.ts adds recordActivity(...)
--   - every ambient/command/inbox call site invokes it after immunity.check()
--
-- Companion change in app (immunity-app):
--   - app/Models/Demo/Brokers/ActivityBroker.php (read + prune)
--   - app/Controllers/Api/Internal/DashboardController.php (/dashboard/activity)
--   - app/Views/dashboard.latte (live table polled every 3s)
--
-- Run on Fly:
--   cat sql/demo/alter-2026-04-29-agent-activity.sql | flyctl ssh console -a immunity-app --pty -C "sh -c 'psql \"\$DATABASE_URL\" -v ON_ERROR_STOP=1 -f -'"
-- =============================================================================

CREATE TABLE IF NOT EXISTS demo.agent_activity
(
    id              bigserial    PRIMARY KEY,
    agent_id        varchar(64)  NOT NULL,
    role            varchar(32)  NOT NULL,
    display_name    varchar(128) NOT NULL,
    action_type     varchar(64)  NOT NULL,
    action_summary  text         NOT NULL,
    status          varchar(16)  NOT NULL,
    antibody_imm_id varchar(32),
    tx_hash         varchar(80),
    target          varchar(80),
    family          varchar(64),
    details         jsonb,
    occurred_at     timestamptz  NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS agent_activity_occurred_at_idx ON demo.agent_activity (occurred_at DESC);
CREATE INDEX IF NOT EXISTS agent_activity_id_desc_idx     ON demo.agent_activity (id DESC);
CREATE INDEX IF NOT EXISTS agent_activity_agent_idx       ON demo.agent_activity (agent_id, occurred_at DESC);
CREATE INDEX IF NOT EXISTS agent_activity_status_idx      ON demo.agent_activity (status);
