CREATE TABLE antibodies (
    id                  bigserial PRIMARY KEY,
    keccak_id           bytea NOT NULL UNIQUE,
    imm_id              varchar(32) NOT NULL UNIQUE,
    type                antibody_type NOT NULL,
    flavor              varchar(32),
    verdict             antibody_verdict NOT NULL,
    confidence          smallint NOT NULL CHECK (confidence BETWEEN 0 AND 100),
    severity            smallint NOT NULL CHECK (severity BETWEEN 0 AND 100),
    status              antibody_status NOT NULL DEFAULT 'active',
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

CREATE INDEX antibodies_created_at_idx ON antibodies (created_at DESC);
CREATE INDEX antibodies_type_idx       ON antibodies (type);
CREATE INDEX antibodies_status_idx     ON antibodies (status);
CREATE INDEX antibodies_publisher_idx  ON antibodies (publisher);
