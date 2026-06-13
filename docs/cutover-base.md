# Prod cutover — 0G/Galileo → Base Sepolia indexer

Owner-run deploy steps for pointing the live indexer at the Base Sepolia suite
(chainId 84532). Run against the prod DB; the work was developed/verified on a
local/forked DB only.

## 1. Deploy

Deploy the `continuity` image (app + indexer).

## 2. Migrations (run in this order)

All are additive/safe on the live DB. Run the enum migration **on its own** —
`ALTER TYPE ... ADD VALUE` cannot run inside a transaction block.

```
sql/antibody/alter-2026-06-13-entry-status-probation.sql     # enum; standalone, no BEGIN/COMMIT
sql/antibody/alter-2026-06-13-entry-bond-model.sql           # stake_amount→bond_amount, +escrow/maturity/seeded/prominence/corroboration
sql/antibody/alter-2026-06-13-publisher-reputation.sql       # publisher score/counters + ENS identity
sql/antibody/alter-2026-06-13-challenge-protected.sql        # antibody.challenge + antibody.protected_target
sql/antibody/alter-2026-06-13-matcher-hash-nonunique.sql     # drop UNIQUE on primary_matcher_hash (corroboration)
```

Per file (Fly):

```
cat sql/antibody/<file>.sql | flyctl ssh console -a immunity-app --pty \
    -C "sh -c 'psql \"\$DATABASE_URL\" -v ON_ERROR_STOP=1 -f -'"
```

(Omit `-v ON_ERROR_STOP=1` is fine; for the probation enum file do NOT wrap in a
transaction.)

## 3. Secrets / env

- `BASE_SEPOLIA_RPC_URL` — set an Alchemy key (the public endpoint rejects wide
  `eth_getLogs` ranges during backfill).
- `LIGHTHOUSE_API_KEY` — already present (gateway).
- Contract addresses + deploy block default in `NetworkConfig::baseSepolia()`;
  override `BASE_*` only on a redeploy.
- Remove any `OG_*` vars — no longer read.

## 4. Clear the stale cursor

```sql
DELETE FROM indexer.state WHERE chain_id = 16602;
```

## 5. Restart + verify

Restart the indexer. `BackfillBootstrap` seeds chain 84532 at the deploy block
(42781168 − 1) and backfills. Watch logs; confirm `antibody.publisher` /
`antibody.protected_target` populate and immunity-protocol.com renders Base data.
Antibody entries appear only once the registry is live-seeded.
