-- ##################################################################################################################
-- DEMO FLEET STATE
-- Tables used by the demo orchestration: agent command queue, fleet pause/resume flag, agent heartbeat.
-- Populated by the demo agent containers in /Users/dtucker/www/immunity-demo and the /playground UI.
-- ##################################################################################################################

-- ##################################################################################################################
-- COMMANDS (operator-injected actions awaiting pickup by a specific agent)
-- ##################################################################################################################
CREATE TABLE demo.commands
(
    id             bigserial   PRIMARY KEY,
    agent_id       varchar(64) NOT NULL,
    command_type   varchar(64) NOT NULL,
    payload        jsonb       NOT NULL,
    scheduled_at   timestamptz NOT NULL DEFAULT now(),
    picked_up_at   timestamptz,
    executed_at    timestamptz,
    result_status  varchar(32) NOT NULL DEFAULT 'pending',
    result_detail  jsonb
);

-- Partial index drives the SELECT FOR UPDATE SKIP LOCKED dequeue path.
CREATE INDEX commands_pending_idx
    ON demo.commands (agent_id, scheduled_at)
    WHERE picked_up_at IS NULL;

CREATE INDEX commands_scheduled_at_idx ON demo.commands (scheduled_at DESC);
CREATE INDEX commands_result_status_idx ON demo.commands (result_status);

-- ##################################################################################################################
-- FLEET_STATE (singleton row holding the global ambient pause flag)
-- ##################################################################################################################
CREATE TABLE demo.fleet_state
(
    id              smallint    PRIMARY KEY DEFAULT 1,
    ambient_paused  boolean     NOT NULL DEFAULT false,
    paused_at       timestamptz,
    CONSTRAINT fleet_state_singleton CHECK (id = 1)
);

INSERT INTO demo.fleet_state (id, ambient_paused) VALUES (1, false)
    ON CONFLICT DO NOTHING;

-- ##################################################################################################################
-- AGENT_HEARTBEAT (one row per running agent; UPSERT on startup and every 60s)
-- Lets the explorer substitute the curated display name for hex addresses.
-- `axl_peer_id` is the agent's full ed25519 public key (64-char hex) read from
-- its own AXL spoke's /topology endpoint. Other agents query this table to
-- resolve a destination peer for /send (the X-From-Peer-Id on /recv is a
-- truncated prefix and cannot be used as a destination — see AXL skill notes).
-- ##################################################################################################################
CREATE TABLE demo.agent_heartbeat
(
    agent_id      varchar(64)  PRIMARY KEY,
    role          varchar(32)  NOT NULL,
    address       bytea        NOT NULL,
    display_name  varchar(128) NOT NULL,
    axl_peer_id   varchar(64),
    last_seen     timestamptz  NOT NULL DEFAULT now()
);

CREATE INDEX agent_heartbeat_address_idx   ON demo.agent_heartbeat (address);
CREATE INDEX agent_heartbeat_role_idx      ON demo.agent_heartbeat (role);
CREATE INDEX agent_heartbeat_last_seen_idx ON demo.agent_heartbeat (last_seen DESC);

-- ##################################################################################################################
-- SOCIAL_FEED (mock "external content" feed — wolves post indirect-injection
-- items here; traders periodically scan unread items and run immunity.check()
-- with the content as ctx.sources.extractedText).
-- `posted_by_agent_id` is null for the seeded benign baseline rows the demo
-- ships with, and set to the wolf's agent_id when a wolf posts.
-- ##################################################################################################################
CREATE TABLE demo.social_feed
(
    id                  bigserial   PRIMARY KEY,
    source              varchar(64) NOT NULL,
    url                 text        NOT NULL,
    content             text        NOT NULL,
    posted_by_agent_id  varchar(64),
    posted_at           timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX social_feed_posted_at_idx ON demo.social_feed (posted_at DESC);
CREATE INDEX social_feed_source_idx    ON demo.social_feed (source);

-- Per-agent read cursor. Avoids re-evaluating the same post; the trader's
-- scan picks the most recent unread row for its own agent_id.
CREATE TABLE demo.social_feed_read
(
    agent_id  varchar(64) NOT NULL,
    feed_id   bigint      NOT NULL REFERENCES demo.social_feed(id) ON DELETE CASCADE,
    read_at   timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (agent_id, feed_id)
);

CREATE INDEX social_feed_read_feed_id_idx ON demo.social_feed_read (feed_id);

-- ##################################################################################################################
-- AGENT_ACTIVITY (per-tick action log; one row per immunity.check / DM / scan / mint / command)
-- Drives the live-activity table on /dashboard so the network looks alive.
-- Pruned by ActivityBroker::pruneOlderThan() to keep the table bounded.
-- ##################################################################################################################
CREATE TABLE demo.agent_activity
(
    id              bigserial    PRIMARY KEY,
    agent_id        varchar(64)  NOT NULL,
    role            varchar(32)  NOT NULL,
    display_name    varchar(128) NOT NULL,
    -- swap | transfer | approve | social_dm_in | social_dm_out | feed_post |
    -- feed_scan | publish_scan | publish_mint | command_attack | ...
    action_type     varchar(64)  NOT NULL,
    action_summary  text         NOT NULL,
    -- allow | block | novel | error | info
    status          varchar(16)  NOT NULL,
    antibody_imm_id varchar(32),
    tx_hash         varchar(80),
    target          varchar(80),
    family          varchar(64),
    details         jsonb,
    occurred_at     timestamptz  NOT NULL DEFAULT now()
);

CREATE INDEX agent_activity_occurred_at_idx ON demo.agent_activity (occurred_at DESC);
CREATE INDEX agent_activity_id_desc_idx     ON demo.agent_activity (id DESC);
CREATE INDEX agent_activity_agent_idx       ON demo.agent_activity (agent_id, occurred_at DESC);
CREATE INDEX agent_activity_status_idx      ON demo.agent_activity (status);
