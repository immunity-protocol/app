CREATE TABLE check_events (
    id                   bigserial PRIMARY KEY,
    agent_id             varchar(128) NOT NULL,
    tx_kind              varchar(64) NOT NULL,
    chain_id             integer NOT NULL,
    decision             check_decision NOT NULL,
    confidence           smallint CHECK (confidence IS NULL OR (confidence BETWEEN 0 AND 100)),
    matched_antibody_id  bigint REFERENCES antibodies (id) ON DELETE SET NULL,
    cache_hit            boolean NOT NULL,
    tee_used             boolean NOT NULL,
    value_at_risk_usd    numeric(20, 6),
    occurred_at          timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX check_events_occurred_at_idx          ON check_events (occurred_at DESC);
CREATE INDEX check_events_agent_id_idx             ON check_events (agent_id);
CREATE INDEX check_events_matched_antibody_id_idx  ON check_events (matched_antibody_id);
CREATE INDEX check_events_decision_idx             ON check_events (decision);
CREATE INDEX check_events_cache_hit_idx            ON check_events (cache_hit);
