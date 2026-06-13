-- =============================================================================
-- alter-2026-06-13-matcher-hash-nonunique.sql
-- Corroboration fix: several distinct publishers each mint their own antibody
-- (own keccak_id) for the SAME primary_matcher_hash to reach corroboration==K.
-- The old UNIQUE index on primary_matcher_hash rejected the 2nd corroborating
-- antibody, breaking the genesis live-seed. Drop it and recreate as a plain
-- (non-unique) partial index. Uniqueness remains on keccak_id.
--
-- Safe on the live DB. Idempotent.
--
-- Run on Fly:
--   cat sql/antibody/alter-2026-06-13-matcher-hash-nonunique.sql | flyctl ssh console \
--       -a immunity-app --pty -C "sh -c 'psql \"\$DATABASE_URL\" -v ON_ERROR_STOP=1 -f -'"
-- =============================================================================

DROP INDEX IF EXISTS antibody.entry_primary_matcher_hash_idx;

CREATE INDEX IF NOT EXISTS entry_primary_matcher_hash_idx
    ON antibody.entry (primary_matcher_hash)
    WHERE primary_matcher_hash IS NOT NULL;
