-- ##################################################################################################################
-- ENUM TYPES
-- ##################################################################################################################
CREATE TYPE antibody.entry_type AS ENUM (
    'address', 'call_pattern', 'bytecode', 'graph', 'semantic'
);

CREATE TYPE antibody.entry_verdict AS ENUM (
    'malicious', 'suspicious'
);

CREATE TYPE antibody.entry_status AS ENUM (
    'active', 'challenged', 'slashed', 'expired'
);

CREATE TYPE antibody.mirror_status AS ENUM (
    'active', 'removed'
);

-- ##################################################################################################################
-- ENTRY (one row per published antibody)
-- ##################################################################################################################
CREATE TABLE antibody.entry
(
    id                  bigserial PRIMARY KEY,
    keccak_id           bytea NOT NULL UNIQUE,
    imm_id              varchar(32) NOT NULL UNIQUE,
    type                antibody.entry_type NOT NULL,
    flavor              varchar(32),
    verdict             antibody.entry_verdict NOT NULL,
    confidence          smallint NOT NULL CHECK (confidence BETWEEN 0 AND 100),
    severity            smallint NOT NULL CHECK (severity BETWEEN 0 AND 100),
    status              antibody.entry_status NOT NULL DEFAULT 'active',
    primary_matcher     jsonb NOT NULL,
    secondary_matchers  jsonb NOT NULL DEFAULT '[]'::jsonb,
    context_hash        bytea NOT NULL,
    evidence_cid        bytea NOT NULL,
    embedding_hash      bytea,
    embedding_cid       bytea,
    stake_lock_until    timestamptz NOT NULL,
    expires_at          timestamptz,
    publisher           bytea NOT NULL,
    publisher_ens       varchar(255),
    stake_amount        numeric(20, 6) NOT NULL,
    attestation         bytea NOT NULL,
    seed_source         varchar(64),
    redacted_reasoning  text,
    created_at          timestamptz NOT NULL DEFAULT now(),
    updated_at          timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX entry_created_at_idx ON antibody.entry (created_at DESC);
CREATE INDEX entry_type_idx       ON antibody.entry (type);
CREATE INDEX entry_status_idx     ON antibody.entry (status);
CREATE INDEX entry_publisher_idx  ON antibody.entry (publisher);

-- ##################################################################################################################
-- MIRROR (per-chain replication of an antibody)
-- ##################################################################################################################
CREATE TABLE antibody.mirror
(
    id                bigserial PRIMARY KEY,
    entry_id          bigint NOT NULL REFERENCES antibody.entry (id) ON DELETE CASCADE,
    chain_id          integer NOT NULL,
    chain_name        varchar(32) NOT NULL,
    mirror_tx_hash    bytea NOT NULL,
    mirrored_at       timestamptz NOT NULL DEFAULT now(),
    status            antibody.mirror_status NOT NULL DEFAULT 'active',
    relayer_address   bytea NOT NULL
);

CREATE UNIQUE INDEX mirror_active_unique_idx
    ON antibody.mirror (entry_id, chain_id)
    WHERE status = 'active';

CREATE INDEX mirror_entry_id_idx    ON antibody.mirror (entry_id);
CREATE INDEX mirror_chain_id_idx    ON antibody.mirror (chain_id);
CREATE INDEX mirror_mirrored_at_idx ON antibody.mirror (mirrored_at DESC);

-- ##################################################################################################################
-- PUBLISHER (aggregate per-publisher stats)
-- ##################################################################################################################
CREATE TABLE antibody.publisher
(
    address                    bytea PRIMARY KEY,
    ens                        varchar(255),
    antibodies_published       integer NOT NULL DEFAULT 0,
    successful_blocks          integer NOT NULL DEFAULT 0,
    total_earned_usdc          numeric(20, 6) NOT NULL DEFAULT 0,
    total_staked_usdc          numeric(20, 6) NOT NULL DEFAULT 0,
    successful_challenges_won  integer NOT NULL DEFAULT 0,
    challenges_lost            integer NOT NULL DEFAULT 0,
    first_seen_at              timestamptz NOT NULL DEFAULT now(),
    last_active_at             timestamptz NOT NULL DEFAULT now(),
    last_ens_resolved_at       timestamptz
);

CREATE INDEX publisher_last_active_at_idx       ON antibody.publisher (last_active_at DESC);
CREATE INDEX publisher_antibodies_published_idx ON antibody.publisher (antibodies_published DESC);
