-- ##################################################################################################################
-- ENUM TYPES
-- ##################################################################################################################
CREATE TYPE event.check_decision AS ENUM (
    'allow', 'block', 'escalate'
);

CREATE TYPE event.activity_type AS ENUM (
    'published', 'protected', 'mirrored', 'challenged', 'released'
);

-- ##################################################################################################################
-- CHECK_EVENT (every SDK check() call lands here; high-volume)
-- ##################################################################################################################
CREATE TABLE event.check_event
(
    id                 bigserial PRIMARY KEY,
    agent_id           varchar(128) NOT NULL,
    tx_kind            varchar(64) NOT NULL,
    chain_id           integer NOT NULL,
    decision           event.check_decision NOT NULL,
    confidence         smallint CHECK (confidence IS NULL OR (confidence BETWEEN 0 AND 100)),
    matched_entry_id   bigint REFERENCES antibody.entry (id) ON DELETE SET NULL,
    cache_hit          boolean NOT NULL,
    tee_used           boolean NOT NULL,
    value_at_risk_usd  numeric(20, 6),
    occurred_at        timestamptz NOT NULL DEFAULT now(),
    tx_hash            bytea,
    log_index          integer,
    UNIQUE (tx_hash, log_index)
);

CREATE INDEX check_event_occurred_at_idx        ON event.check_event (occurred_at DESC);
CREATE INDEX check_event_agent_id_idx           ON event.check_event (agent_id);
CREATE INDEX check_event_matched_entry_id_idx   ON event.check_event (matched_entry_id);
CREATE INDEX check_event_decision_idx           ON event.check_event (decision);
CREATE INDEX check_event_cache_hit_idx          ON event.check_event (cache_hit);

-- ##################################################################################################################
-- BLOCK_EVENT (denormalized: subset of check_event where decision = block)
-- ##################################################################################################################
CREATE TABLE event.block_event
(
    id                   bigserial PRIMARY KEY,
    check_event_id       bigint NOT NULL REFERENCES event.check_event (id) ON DELETE CASCADE,
    entry_id             bigint NOT NULL REFERENCES antibody.entry (id) ON DELETE CASCADE,
    agent_id             varchar(128) NOT NULL,
    value_protected_usd  numeric(20, 6) NOT NULL,
    tx_hash_attempt      bytea,
    chain_id             integer NOT NULL,
    occurred_at          timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX block_event_occurred_at_idx ON event.block_event (occurred_at DESC);
CREATE INDEX block_event_entry_id_idx    ON event.block_event (entry_id);
CREATE INDEX block_event_chain_id_idx    ON event.block_event (chain_id);

-- ##################################################################################################################
-- ACTIVITY (denormalized activity feed for the landing page)
-- ##################################################################################################################
CREATE TABLE event.activity
(
    id           bigserial PRIMARY KEY,
    event_type   event.activity_type NOT NULL,
    entry_id     bigint REFERENCES antibody.entry (id) ON DELETE CASCADE,
    payload      jsonb NOT NULL DEFAULT '{}'::jsonb,
    actor        varchar(255) NOT NULL,
    occurred_at  timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX activity_occurred_at_idx ON event.activity (occurred_at DESC);
CREATE INDEX activity_event_type_idx  ON event.activity (event_type);
CREATE INDEX activity_entry_id_idx    ON event.activity (entry_id);

-- ##################################################################################################################
-- SWEEP_EVENT (one row per StakeSwept emission from the Registry)
-- ##################################################################################################################
CREATE TABLE event.sweep_event
(
    id            bigserial PRIMARY KEY,
    sweeper       bytea          NOT NULL,
    num_released  integer        NOT NULL,
    bounty_paid   numeric(20, 6) NOT NULL,
    occurred_at   timestamptz    NOT NULL DEFAULT now(),
    block_number  bigint         NOT NULL,
    tx_hash       bytea          NOT NULL,
    log_index     integer        NOT NULL,
    UNIQUE (tx_hash, log_index)
);

CREATE INDEX sweep_event_occurred_at_idx ON event.sweep_event (occurred_at DESC);
CREATE INDEX sweep_event_sweeper_idx     ON event.sweep_event (sweeper);

-- ##################################################################################################################
-- CONTRACT_EVENT (generic audit log for Registry events with no dedicated table)
-- ##################################################################################################################
CREATE TABLE event.contract_event
(
    id            bigserial PRIMARY KEY,
    event_name    varchar(64) NOT NULL,
    payload       jsonb       NOT NULL DEFAULT '{}'::jsonb,
    block_number  bigint      NOT NULL,
    tx_hash       bytea       NOT NULL,
    log_index     integer     NOT NULL,
    occurred_at   timestamptz NOT NULL DEFAULT now(),
    UNIQUE (tx_hash, log_index)
);

CREATE INDEX contract_event_occurred_at_idx ON event.contract_event (occurred_at DESC);
CREATE INDEX contract_event_event_name_idx  ON event.contract_event (event_name);
