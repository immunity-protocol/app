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
