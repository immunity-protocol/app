CREATE TABLE mirrors (
    id                bigserial PRIMARY KEY,
    antibody_id       bigint NOT NULL REFERENCES antibodies (id) ON DELETE CASCADE,
    chain_id          integer NOT NULL,
    chain_name        varchar(32) NOT NULL,
    mirror_tx_hash    bytea NOT NULL,
    mirrored_at       timestamptz NOT NULL DEFAULT now(),
    status            mirror_status NOT NULL DEFAULT 'active',
    relayer_address   bytea NOT NULL
);

CREATE UNIQUE INDEX mirrors_active_unique_idx
    ON mirrors (antibody_id, chain_id)
    WHERE status = 'active';

CREATE INDEX mirrors_antibody_id_idx ON mirrors (antibody_id);
CREATE INDEX mirrors_chain_id_idx    ON mirrors (chain_id);
CREATE INDEX mirrors_mirrored_at_idx ON mirrors (mirrored_at DESC);
