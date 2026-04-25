CREATE TABLE agent_heartbeats (
    agent_id     varchar(128) PRIMARY KEY,
    agent_ens    varchar(255),
    agent_role   agent_role NOT NULL,
    last_seen    timestamptz NOT NULL DEFAULT now(),
    peer_count   integer NOT NULL DEFAULT 0,
    version      varchar(32) NOT NULL,
    metadata     jsonb NOT NULL DEFAULT '{}'::jsonb
);

CREATE INDEX agent_heartbeats_last_seen_idx ON agent_heartbeats (last_seen DESC);
CREATE INDEX agent_heartbeats_role_idx      ON agent_heartbeats (agent_role);
