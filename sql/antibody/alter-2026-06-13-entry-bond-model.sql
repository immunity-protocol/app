-- =============================================================================
-- alter-2026-06-13-entry-bond-model.sql
-- Migrate antibody.entry from the old stake model to the Base bond model:
--   * stake_amount      -> bond_amount   (the publisher's locked USDC bond)
--   * stake_lock_until  -> dropped       (replaced by matured_at semantics)
--   * + escrowed_fees                    (publisher fees held until maturation)
--   * + matured_at                       (NULL until the antibody matures)
--   * + is_seeded                        (1 = genesis-seeded; no bond/escrow)
--   * + prominence_tier                  (0 normal, 1 protected; cached at publish)
--   * + corroboration_count              (denormalized for the list/detail UI)
--
-- Additive + rename; existing rows preserved (stake_amount values carry over as
-- bond_amount). Safe to run inside a transaction.
--
-- Run on Fly:
--   cat sql/antibody/alter-2026-06-13-entry-bond-model.sql | flyctl ssh console \
--       -a immunity-app --pty -C "sh -c 'psql \"\$DATABASE_URL\" -v ON_ERROR_STOP=1 -f -'"
-- =============================================================================

ALTER TABLE antibody.entry
    ADD COLUMN IF NOT EXISTS escrowed_fees       numeric(20, 6) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS matured_at          timestamptz,
    ADD COLUMN IF NOT EXISTS is_seeded           smallint NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS prominence_tier     smallint NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS corroboration_count smallint NOT NULL DEFAULT 0;

DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'antibody' AND table_name = 'entry'
          AND column_name = 'stake_amount'
    ) THEN
        ALTER TABLE antibody.entry RENAME COLUMN stake_amount TO bond_amount;
    END IF;
END $$;

ALTER TABLE antibody.entry DROP COLUMN IF EXISTS stake_lock_until;
