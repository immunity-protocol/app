-- =============================================================================
-- alter-2026-06-13-challenge-protected.sql
-- Phase 2 tables: antibody.challenge (challenge/jury disputes) and
-- antibody.protected_target (the protocol's protected-address set). Both are
-- new tables, safe to run on the live DB. Idempotent via IF NOT EXISTS.
--
-- Run on Fly:
--   cat sql/antibody/alter-2026-06-13-challenge-protected.sql | flyctl ssh console \
--       -a immunity-app --pty -C "sh -c 'psql \"\$DATABASE_URL\" -v ON_ERROR_STOP=1 -f -'"
-- =============================================================================

CREATE TABLE IF NOT EXISTS antibody.challenge
(
    id              bigserial PRIMARY KEY,
    keccak_id       bytea NOT NULL UNIQUE,
    challenger      bytea,
    bond            numeric(20, 6),
    status          varchar(20) NOT NULL DEFAULT 'LAYER1_PENDING'
                    CHECK (status IN ('NONE', 'LAYER1_PENDING', 'LAYER2_ESCALATED', 'RESOLVED')),
    evidence_cid    bytea,
    invalid_votes   smallint NOT NULL DEFAULT 0,
    valid_votes     smallint NOT NULL DEFAULT 0,
    is_invalid      boolean,
    winner_payout   numeric(20, 6),
    juror_fee       numeric(20, 6),
    treasury_amount numeric(20, 6),
    opened_at       timestamptz NOT NULL DEFAULT now(),
    escalated_at    timestamptz,
    resolved_at     timestamptz
);

CREATE INDEX IF NOT EXISTS challenge_status_idx    ON antibody.challenge (status);
CREATE INDEX IF NOT EXISTS challenge_opened_at_idx ON antibody.challenge (opened_at DESC);

CREATE TABLE IF NOT EXISTS antibody.protected_target
(
    address     bytea PRIMARY KEY,
    protected   boolean NOT NULL DEFAULT true,
    updated_at  timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS protected_target_protected_idx ON antibody.protected_target (protected);
