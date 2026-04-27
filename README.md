# Immunity

Decentralized threat intelligence for AI agents.

When an AI agent encounters a malicious actor, contract, social engineering attempt, or prompt injection, it publishes a signed, staked **antibody** to a global network. Every other agent's local cache updates within seconds and the same attack is blocked everywhere.

This repository contains the Immunity application: web frontend, REST API, indexer, relayer, and seeder. Built on the Zephyrus 2 PHP framework with PostgreSQL.

## Quick start

```bash
composer install
cp .env.example .env
docker compose up
```

Then open [http://localhost](http://localhost).

The compose stack runs four services:

| Service | Purpose |
|---|---|
| `database` | Postgres 15 with the application schema |
| `webserver` | Apache + PHP serving the Latte frontend on `:80` |
| `api` | Apache + PHP serving the REST API on `:8081` |
| `indexer` | Long-running PHP worker that reads on-chain events from the deployed 0G Galileo Registry, hydrates 0G Storage envelopes, refreshes network metrics every 60s, and reverse-resolves publisher ENS names. No separate seeder is needed — the indexer backfills from the contract's deploy block on cold start. |

Requires PHP 8.4+ with `mbstring`, `pdo`, `intl`, and `sodium` extensions if you ever need to run `composer` outside Docker.

## Deployment (Fly.io)

Production runs on a single Fly machine that bundles Apache + PHP + PostgreSQL together. Database files persist on a Fly volume mounted at `/data`.

Live at [immunity-app.fly.dev](https://immunity-app.fly.dev).

### One-time setup

Requires the `fly` CLI authenticated against the `ophelios` org.

```bash
fly apps create immunity-app --org ophelios
fly volumes create pg_data --size 1 --region yyz --app immunity-app

# Generate a 32-byte XChaCha20-Poly1305 key (base64url-encoded) and a DB password.
ENC_KEY=$(php -r "echo rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');")
DB_PASS=$(php -r "echo bin2hex(random_bytes(24));")
fly secrets set ENCRYPTION_KEY="$ENC_KEY" DB_PASSWORD="$DB_PASS" --app immunity-app --stage
```

### Deploy

```bash
fly deploy --app immunity-app --ha=false
```

The first deploy initializes the Postgres cluster on the volume and applies `sql/init.sql`. Subsequent deploys skip the init step.

### Operate

```bash
fly logs --app immunity-app                  # live logs
fly ssh console --app immunity-app           # shell into the running machine
fly status --app immunity-app                # machine + volume state
fly secrets list --app immunity-app          # what is set, no values
```

Inside the machine, `psql -h localhost -U immunity -d immunity` opens the live database (password is the staged `DB_PASSWORD`).

### Configuration files

- `fly.toml` — app config (region, mounts, vm specs, env)
- `.flyio/Dockerfile` — single-image build (PHP 8.4 + Postgres 15 + Apache)
- `.flyio/supervisord.conf` — orchestrates postgres, init-db, and apache as siblings
- `.flyio/scripts/init-db.sh` — idempotent role/database/schema bootstrap
- `.flyio/vhosts/default.conf` — Apache vhost pointing at `public/`
- `.flyio/php-production.ini` — production php.ini overrides

## Indexer

`bin/indexer.php` is a single PHP CLI process running multiple cadence-aware loops:

| Loop | Cadence | Concern |
|---|---|---|
| EventPoller | 2s (configurable) | `eth_getLogs` against the Galileo Registry, dispatch to handlers |
| HydrationWorker | 2s, capped at N jobs/tick | Drain `indexer.hydration_queue`, fetch envelope JSON via `node scripts/og-download.mjs`, fill `primary_matcher`/`redacted_reasoning` on the antibody row |
| ExpirySweep | 60s | `UPDATE antibody.entry SET status='expired' WHERE expires_at < now()` (the contract emits no expiry event) |
| EnsResolutionWorker | 30s | Reverse-resolve `antibody.publisher.address` → ENS name via `ophelios/php-ethereum-ens`, cached with TTL in Postgres |
| StatRefresher | 60s | Recompute the 5 dashboard metrics from real DB state and insert into `network.stat` (drives the "Live" indicator) |

### Local

```bash
docker compose up indexer        # builds the image (Node 20 + 0G SDK) and starts the worker
docker compose logs -f indexer   # see boot, summary stats, errors
```

### Reset state

```bash
docker compose exec database psql -U dev -d immunity -c "
  TRUNCATE event.activity, event.block_event, event.check_event,
           event.contract_event, event.sweep_event,
           antibody.mirror, antibody.entry, antibody.publisher,
           indexer.hydration_queue, indexer.state, network.stat
    RESTART IDENTITY CASCADE;"
docker compose restart indexer   # backfills from the deploy block again
```

### Configuration

All env vars have safe testnet defaults; override via `.env` or `docker-compose.yml`:

| Env var | Default |
|---|---|
| `OG_RPC_URL` | `https://evmrpc-testnet.0g.ai` |
| `OG_REGISTRY_ADDRESS` | `0x45Ee45Ca358b3fc9B1b245a8f1c1C3128caC8e48` |
| `OG_STORAGE_INDEXER` | `https://indexer-storage-testnet-turbo.0g.ai` |
| `ETH_RPC_URL` | `https://eth.llamarpc.com` (mainnet, ENS only) |
| `INDEXER_POLL_INTERVAL_MS` | `2000` |
| `INDEXER_HYDRATION_CONCURRENCY` | `5` (jobs per tick, sequential) |
| `INDEXER_BACKFILL_CHUNK` | `5000` (blocks per `eth_getLogs` call) |
| `INDEXER_CONFIRMATIONS` | `2` (head depth treated as final) |

### Known limitations

- 0G Storage hydration may briefly lag the on-chain event ingest (it runs in the same loop, capped per tick). Antibody rows appear immediately with NULL `primary_matcher`; the worker fills it shortly.
- Expiry status flips up to 60 seconds after `expires_at`. The contract intentionally emits no expiry event.
- Reorg policy is N=2 confirmations (testnet-grade). Mainnet would need a deeper window and a hash-based detector; revisit before mainnet deploy.
- ENS reverse resolution depends on a public Ethereum mainnet RPC and is best-effort. Failures back off for 24h.

## Value-protected telemetry

The Registry contract's `CheckSettled` event carries `(agent, antibodyId, wasMatch, fee, timestamp)` only — no value channel. To populate the per-antibody and system-wide "value protected" metrics, agents (or any trusted reporter) post the at-risk USD amount of the blocked transaction to a small internal endpoint:

```
POST /v1/internal/value-protected
Header: X-CRON-TOKEN: <secret>
Body  : { "tx_hash": "0x…", "value_at_risk_usd": "12345.67" }
```

Behavior:
- Looks up `event.check_event` rows by `tx_hash` and stamps `value_at_risk_usd`.
- Mirrors the value into `event.block_event.value_protected_usd` for blocks tied to that tx.
- 200 with `{updated: N}` when at least one row matched; 202 when no row matched yet (out-of-order arrival; the SDK can retry or skip).
- Idempotent: reposting the same value is a no-op.

The aggregate dashboard tile (`value_protected_usd`) reads `SUM(value_protected_usd)` from `event.block_event` via the indexer's `StatRefresher`, so once telemetry arrives the home + dashboard tiles light up automatically.

For demo seeding (no SDK telemetry yet), use the bundled CLI:

```bash
docker compose exec api php bin/record-value-protected.php <tx_hash> <usd>
```

SDK integration is out of scope for this repo. The follow-up in `~/www/immunity-sdk` is to extend `settleCheck()` to POST `{tx_hash, valueAtRiskUsd}` after the chain confirms the block, derived from the SDK's `ProposedTx.value` * a USD oracle quote, best-effort (a failed POST does not affect the on-chain block).

## Project layout

```
app/
  Controllers/   Route controllers (auto-discovered via attributes)
  Models/        Domain models, services, brokers, indexer worker code
bin/
  indexer.php    Long-running indexer process (CLI bootstrap + Supervisor loop)
scripts/
  og-download.mjs  Node helper around @0gfoundation/0g-ts-sdk for envelope downloads
tests/
  fixtures/Mock/ Mock data factories (kept here for use by integration tests)
  Views/         Latte templates
config.yml       Application configuration
docker/          Docker service definitions
locale/          Translation files
public/          Web root, static assets, front controller
sql/             Database schema and seed data
```

## License

MIT, see [LICENSE](LICENSE).
