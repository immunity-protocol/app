-- =============================================================================
-- alter-2026-04-29-publisher-reward.sql
-- Add per-block publisher reward to event.block_event so the antibody detail
-- page can display "publisher earnings = $X.XXXX from N matches" without
-- re-deriving from the contract every time.
--
-- Idempotent: ADD COLUMN IF NOT EXISTS + a one-shot backfill at the standard
-- protocol fee (0.0016 USDC = 0.002 USDC × 80%) for any rows that pre-date
-- the indexer change.
--
-- Run on Fly:
--   cat sql/event/alter-2026-04-29-publisher-reward.sql | flyctl ssh console \
--       -a immunity-app --pty -C "sh -c 'psql \"\$DATABASE_URL\" -v ON_ERROR_STOP=1 -f -'"
-- =============================================================================

ALTER TABLE event.block_event
    ADD COLUMN IF NOT EXISTS publisher_reward_usdc numeric(20, 6);

UPDATE event.block_event
   SET publisher_reward_usdc = 0.0016
 WHERE publisher_reward_usdc IS NULL;
