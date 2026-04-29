-- =============================================================================
-- alter-2026-04-fleet-evolution.sql
-- Apply ON THE LIVE DATABASE to upgrade demo.* in place. Idempotent: every
-- statement uses IF NOT EXISTS so a repeat run is a no-op.
--
-- Companion changes in agents (immunity-demo):
--   - axl_peer_id is written by the agent at boot from its AXL /topology call
--   - demo.social_feed is read by traders (scan) and written by wolves (post)
--   - demo.social_feed_read tracks per-agent read cursor
--
-- Run on Fly:
--   fly postgres connect -a <pg-app>
--   \i sql/demo/alter-2026-04-fleet-evolution.sql
-- =============================================================================

-- AXL peer-id column on the heartbeat row.
ALTER TABLE demo.agent_heartbeat
    ADD COLUMN IF NOT EXISTS axl_peer_id varchar(64);

-- Social feed: mock external content channel for indirect-injection demos.
CREATE TABLE IF NOT EXISTS demo.social_feed
(
    id                  bigserial   PRIMARY KEY,
    source              varchar(64) NOT NULL,
    url                 text        NOT NULL,
    content             text        NOT NULL,
    posted_by_agent_id  varchar(64),
    posted_at           timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS social_feed_posted_at_idx ON demo.social_feed (posted_at DESC);
CREATE INDEX IF NOT EXISTS social_feed_source_idx    ON demo.social_feed (source);

CREATE TABLE IF NOT EXISTS demo.social_feed_read
(
    agent_id  varchar(64) NOT NULL,
    feed_id   bigint      NOT NULL REFERENCES demo.social_feed(id) ON DELETE CASCADE,
    read_at   timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (agent_id, feed_id)
);

CREATE INDEX IF NOT EXISTS social_feed_read_feed_id_idx ON demo.social_feed_read (feed_id);
