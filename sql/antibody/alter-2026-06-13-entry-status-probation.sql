-- =============================================================================
-- alter-2026-06-13-entry-status-probation.sql
-- Add the 'probation' lifecycle entry state to antibody.entry_status. On Base,
-- a freshly published antibody enters PROBATION (advisory, fees escrowed) and
-- only becomes 'active' once it matures.
--
-- NOTE: ALTER TYPE ... ADD VALUE cannot run inside a transaction block. Run
-- this file on its own (psql -f), not wrapped in BEGIN/COMMIT.
--
-- Run on Fly:
--   cat sql/antibody/alter-2026-06-13-entry-status-probation.sql | flyctl ssh console \
--       -a immunity-app --pty -C "sh -c 'psql \"\$DATABASE_URL\" -f -'"
-- =============================================================================

ALTER TYPE antibody.entry_status ADD VALUE IF NOT EXISTS 'probation' BEFORE 'active';
