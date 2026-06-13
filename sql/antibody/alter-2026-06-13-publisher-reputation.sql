-- =============================================================================
-- alter-2026-06-13-publisher-reputation.sql
-- Extend antibody.publisher with the reputation mirror + identity fields driven
-- by the Base Reputation and PublisherRegistrar events. The canonical score is
-- the on-chain Reputation contract; these columns are display-only.
--
-- Additive only; existing rows preserved. Safe inside a transaction.
--
-- Run on Fly:
--   cat sql/antibody/alter-2026-06-13-publisher-reputation.sql | flyctl ssh console \
--       -a immunity-app --pty -C "sh -c 'psql \"\$DATABASE_URL\" -v ON_ERROR_STOP=1 -f -'"
-- =============================================================================

ALTER TABLE antibody.publisher
    ADD COLUMN IF NOT EXISTS score             numeric(20, 6) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS matured_count     bigint NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS challenges_won    bigint NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS slashed_count     bigint NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS genesis_granted   numeric(20, 6) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS ens_node          bytea,
    ADD COLUMN IF NOT EXISTS registration_bond numeric(20, 6),
    ADD COLUMN IF NOT EXISTS registered_at     timestamptz,
    ADD COLUMN IF NOT EXISTS deregistered      boolean NOT NULL DEFAULT false;
