-- ##################################################################################################################
-- JOB ENUMS
-- ##################################################################################################################
-- job_type: which Mirror function the relayer should invoke for this job.
--   mirror          -> Mirror.mirrorAntibody(antibody, auxiliaryKey)
--   mirror_address  -> Mirror.mirrorAddressAntibody(antibody, target)  (one tx vs mirror+setAddressBlock)
--   unmirror        -> Mirror.unmirrorAntibody(keccakId)
CREATE TYPE mirror.job_type AS ENUM ('mirror', 'mirror_address', 'unmirror');

-- job_status lifecycle:
--   pending    -> ready to claim
--   in_flight  -> claimed by a relayer worker, Node helper running
--   sent       -> tx submitted and mined; awaiting indexer confirmation
--   confirmed  -> indexer observed the corresponding event on the destination chain
--   failed     -> permanent classification or max retries exceeded
CREATE TYPE mirror.job_status AS ENUM ('pending', 'in_flight', 'sent', 'confirmed', 'failed');

-- ##################################################################################################################
-- PENDING_JOBS (relayer work queue)
-- ##################################################################################################################
-- Populated by the indexer (AntibodyPublishedHandler / AntibodySlashedHandler)
-- and consumed by the relayer worker. The full antibody envelope is snapshotted
-- into payload at enqueue time so the relayer never re-reads antibody.entry.
--
-- No FK to antibody.entry: keccak_id is the natural key, FK would force
-- enqueue ordering and cascade deletes would lose history.
CREATE TABLE mirror.pending_jobs
(
    id              bigserial   PRIMARY KEY,
    keccak_id       bytea       NOT NULL,
    target_chain_id integer     NOT NULL,
    job_type        mirror.job_type   NOT NULL,
    payload         jsonb       NOT NULL DEFAULT '{}'::jsonb,
    enqueued_at     timestamptz NOT NULL DEFAULT now(),
    next_attempt_at timestamptz NOT NULL DEFAULT now(),
    attempts        smallint    NOT NULL DEFAULT 0,
    last_error      text,
    status          mirror.job_status NOT NULL DEFAULT 'pending',
    tx_hash         bytea,
    sent_at         timestamptz,
    confirmed_at    timestamptz,
    claimed_at      timestamptz,
    UNIQUE (keccak_id, target_chain_id, job_type)
);

CREATE INDEX pending_jobs_ready_idx
    ON mirror.pending_jobs (next_attempt_at)
    WHERE status = 'pending';

CREATE INDEX pending_jobs_keccak_idx ON mirror.pending_jobs (keccak_id);

CREATE INDEX pending_jobs_in_flight_idx
    ON mirror.pending_jobs (claimed_at)
    WHERE status = 'in_flight';
