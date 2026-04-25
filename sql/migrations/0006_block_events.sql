CREATE TABLE block_events (
    id                   bigserial PRIMARY KEY,
    check_event_id       bigint NOT NULL REFERENCES check_events (id) ON DELETE CASCADE,
    antibody_id          bigint NOT NULL REFERENCES antibodies (id) ON DELETE CASCADE,
    agent_id             varchar(128) NOT NULL,
    value_protected_usd  numeric(20, 6) NOT NULL,
    tx_hash_attempt      bytea,
    chain_id             integer NOT NULL,
    occurred_at          timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX block_events_occurred_at_idx ON block_events (occurred_at DESC);
CREATE INDEX block_events_antibody_id_idx ON block_events (antibody_id);
CREATE INDEX block_events_chain_id_idx    ON block_events (chain_id);
