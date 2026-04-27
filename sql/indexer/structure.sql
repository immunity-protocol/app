-- ##################################################################################################################
-- STATE (one row per indexed chain tracking the indexer's progress through history)
-- ##################################################################################################################
-- Chain examples: 16602 (0G Galileo Registry), 11155111 (Sepolia Mirror).
-- Rows are seeded at process start by BackfillBootstrap based on each chain's
-- deploy block; this table starts empty.
CREATE TABLE indexer.state
(
    chain_id             integer     PRIMARY KEY,
    last_processed_block bigint      NOT NULL DEFAULT 0,
    mode                 varchar(16) NOT NULL DEFAULT 'live',
    last_run_at          timestamptz NOT NULL DEFAULT now()
);

-- ##################################################################################################################
-- HYDRATION_QUEUE (jobs to fetch antibody envelopes from 0G Storage)
-- ##################################################################################################################
CREATE TABLE indexer.hydration_queue
(
    id                 bigserial   PRIMARY KEY,
    antibody_keccak_id bytea       NOT NULL,
    evidence_cid       bytea       NOT NULL,
    enqueued_at        timestamptz NOT NULL DEFAULT now(),
    attempts           smallint    NOT NULL DEFAULT 0,
    last_error         text,
    status             varchar(16) NOT NULL DEFAULT 'pending'
);

CREATE INDEX hydration_queue_pending_idx
    ON indexer.hydration_queue (status, enqueued_at)
    WHERE status = 'pending';

-- ##################################################################################################################
-- TOKEN_PRICE_CACHE (Postgres-backed cache for Moralis token price lookups)
-- ##################################################################################################################
-- Mirrors the ENS resolution caching pattern: hit Moralis once per (token,
-- chain), persist the result, refresh when stale. 5-minute TTL is enforced
-- by the application layer (price service checks `fetched_at`).
--
-- Stale rows are NOT deleted — they remain a usable fallback if Moralis is
-- temporarily unreachable.
CREATE TABLE indexer.token_price_cache
(
    token_address  bytea          NOT NULL,
    chain_id       integer        NOT NULL,
    usd_price      numeric(38, 18) NOT NULL,
    decimals       smallint       NOT NULL,
    symbol         varchar(64),
    fetched_at     timestamptz    NOT NULL DEFAULT now(),
    PRIMARY KEY (token_address, chain_id)
);

CREATE INDEX idx_token_price_fetched_at ON indexer.token_price_cache (fetched_at);
