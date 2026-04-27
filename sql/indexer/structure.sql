-- ##################################################################################################################
-- STATE (singleton row tracking the indexer's progress through chain history)
-- ##################################################################################################################
CREATE TABLE indexer.state
(
    id                   smallint PRIMARY KEY DEFAULT 1,
    last_processed_block bigint      NOT NULL DEFAULT 0,
    mode                 varchar(16) NOT NULL DEFAULT 'live',
    last_run_at          timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT state_singleton CHECK (id = 1)
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
