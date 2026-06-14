-- ##################################################################################################################
-- ENUM TYPES
-- ##################################################################################################################
CREATE TYPE agent.role AS ENUM (
    'trader', 'publisher', 'watcher', 'relay'
);

-- ##################################################################################################################
-- HEARTBEAT (one row per agent, refreshed on each ping)
-- ##################################################################################################################
CREATE TABLE agent.heartbeat
(
    agent_id     varchar(128) PRIMARY KEY,
    agent_ens    varchar(255),
    agent_role   agent.role NOT NULL,
    last_seen    timestamptz NOT NULL DEFAULT now(),
    peer_count   integer NOT NULL DEFAULT 0,
    version      varchar(32) NOT NULL,
    metadata     jsonb NOT NULL DEFAULT '{}'::jsonb
);

CREATE INDEX heartbeat_last_seen_idx ON agent.heartbeat (last_seen DESC);
CREATE INDEX heartbeat_role_idx      ON agent.heartbeat (agent_role);

-- ##################################################################################################################
-- FLEET_MEMBER (one row per template-agent in the live fleet; UPSERT on startup and every heartbeat)
-- The real product fleet (publisher / hunter / corroborator) that runs the public
-- template agent and reports over HTTP to POST /v1/agents/heartbeat. Distinct from
-- agent.heartbeat above (network-node liveness) and from the old demo.agent_heartbeat
-- (AXL demo containers). `wallet` is the agent's Base Sepolia address as 0x text;
-- null until the SDK binds identity. `ens` is its *.immunity.eth name once registered.
-- ##################################################################################################################
CREATE TABLE agent.fleet_member
(
    agent_id      varchar(128) PRIMARY KEY,
    role          varchar(32)  NOT NULL,
    display_name  varchar(128) NOT NULL,
    wallet        varchar(42),
    ens           varchar(255),
    version       varchar(32)  NOT NULL,
    first_seen    timestamptz  NOT NULL DEFAULT now(),
    last_seen     timestamptz  NOT NULL DEFAULT now()
);

CREATE INDEX fleet_member_last_seen_idx ON agent.fleet_member (last_seen DESC);
CREATE INDEX fleet_member_role_idx      ON agent.fleet_member (role);

-- ##################################################################################################################
-- FLEET_ACTIVITY (per-action log; one row per check / publish / corroborate / challenge / scan a fleet agent runs)
-- Mirrors the demo.agent_activity shape so the activity panel renders it unchanged.
-- Drives the live feed on /agents. Append-only; bounded by periodic pruning.
-- ##################################################################################################################
CREATE TABLE agent.fleet_activity
(
    id              bigserial    PRIMARY KEY,
    agent_id        varchar(128) NOT NULL,
    role            varchar(32)  NOT NULL,
    display_name    varchar(128) NOT NULL,
    -- check | publish | corroborate | challenge | scan | ...
    action_type     varchar(64)  NOT NULL,
    action_summary  text         NOT NULL,
    -- allow | block | novel | error | info
    status          varchar(16)  NOT NULL,
    antibody_imm_id varchar(32),
    tx_hash         varchar(80),
    target          varchar(80),
    family          varchar(64),
    occurred_at     timestamptz  NOT NULL DEFAULT now()
);

CREATE INDEX fleet_activity_occurred_at_idx ON agent.fleet_activity (occurred_at DESC);
CREATE INDEX fleet_activity_id_desc_idx     ON agent.fleet_activity (id DESC);
CREATE INDEX fleet_activity_agent_idx       ON agent.fleet_activity (agent_id, occurred_at DESC);
CREATE INDEX fleet_activity_status_idx      ON agent.fleet_activity (status);

-- ##################################################################################################################
-- FLEET_CONTROL (singleton row: the judge-operated fleet pause flag)
-- Agents poll GET /v1/agents/control each tick and idle while paused (their
-- heartbeat keeps running, so they stay online). The playground flips it.
-- ##################################################################################################################
CREATE TABLE agent.fleet_control
(
    id          smallint    PRIMARY KEY DEFAULT 1,
    paused      boolean     NOT NULL DEFAULT false,
    updated_at  timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT fleet_control_singleton CHECK (id = 1)
);

INSERT INTO agent.fleet_control (id, paused) VALUES (1, false) ON CONFLICT DO NOTHING;

-- ##################################################################################################################
-- SOCIAL_POST (the fake on-chain-agent social network feed)
-- Trader agents post benign chatter and consume the feed; wolf agents plant
-- poisoned content (prompt-injection / scam bait from the curated incident
-- catalog). The /feed page renders posts with the author's ENS + avatar and
-- flags the malicious ones. `family` is the attack-family id for malicious posts.
-- ##################################################################################################################
CREATE TABLE agent.social_post
(
    id              bigserial    PRIMARY KEY,
    author_address  varchar(42)  NOT NULL,
    author_label    varchar(128) NOT NULL,
    author_ens      varchar(255),
    author_kind     varchar(16)  NOT NULL DEFAULT 'trader',   -- trader | wolf
    source          varchar(32)  NOT NULL DEFAULT 'web',      -- twitter | reddit | discord | ...
    content         text         NOT NULL,
    is_malicious    boolean      NOT NULL DEFAULT false,
    family          varchar(64),                              -- attack family id (malicious only)
    flavor          varchar(32),                              -- PROMPT_INJECTION | MANIPULATION | COUNTERPARTY
    posted_at       timestamptz  NOT NULL DEFAULT now()
);

CREATE INDEX social_post_posted_at_idx ON agent.social_post (posted_at DESC);
CREATE INDEX social_post_id_desc_idx   ON agent.social_post (id DESC);
CREATE INDEX social_post_malicious_idx ON agent.social_post (is_malicious);
