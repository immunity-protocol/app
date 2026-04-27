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
