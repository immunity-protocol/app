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

## Infrastructure

The full prod stack is **6 Fly.io apps in the `ophelios` org, all in `yyz`**, plus two AXL gossip hubs the team operates separately. Everything reaches the same managed Postgres via `*.flycast` internal DNS.

```
┌──────── Fly.io / ophelios ──────────────────────────────────────────────┐
│                                                                         │
│  immunity-app (WEB)        immunity-api (API)        immunity-indexer   │
│  ↳ web UI + dashboard      ↳ public REST + cron      ↳ event poller +   │
│  ↳ /playground gate                                    hydration worker │
│           │                          │                         │        │
│           └──────────┬───────────────┴────────────┬───────────┘        │
│                      ▼                            ▼                     │
│              immunity-db (Fly Postgres) ◄── immunity-relayer (RELAYER) │
│                      ▲                                                  │
│                      │ writes heartbeats + reads commands               │
│                      │                                                  │
│              immunity-fleet (DEMO)                                      │
│              ↳ axl-spoke + 60 agents under supervisord                  │
│                                                                         │
└─────────────────────────────────────────────────────────────────────────┘

Outside Fly:
  • 0G Galileo testnet RPC + Registry contract (chain-side)
  • 0G Storage indexer (envelope read/write)
  • Sepolia RPC (cross-chain mirror destination)
  • hub-can.immunity-protocol.com:9001, hub-usa.immunity-protocol.com:9001
    (AXL gossip hubs the spoke dials out to; deployed from immunity-axl-hub)
```

### Per-app role

| App | Mode | Role | VM | Memory | Public? | Secrets |
|---|---|---|---|---|---|---|
| `immunity-app` | `WEB` | Public site at immunity-protocol.com. Landing, dashboard, antibody explorer, antibody detail, RSS / JSON feeds, the password-gated `/playground` console. | shared-cpu-1x | 512 MB | yes (HTTPS) | `DATABASE_URL`, `ENCRYPTION_KEY`, `PLAYGROUND_PASSWORD`, `ADMIN_PASSWORD` |
| `immunity-api` | `API` | Public developer REST API at api.immunity-protocol.com (`/v1/antibodies`, `/v1/internal/*`). Two processes from one image: `app` (Apache) and `cron` (supercronic). | shared-cpu-1x ×2 | 512 + 256 MB | yes (HTTPS, app only) | `DATABASE_URL`, `CRON_TOKEN` |
| `immunity-indexer` | `INDEXER` | Single long-running PHP CLI process (`bin/indexer.php`). Workers: EventPoller (2s) → 0G chain logs, HydrationWorker (2s) → downloads envelopes from 0G storage, StatRefresher (60s) → recomputes the dashboard tiles, EnsResolutionWorker (30s). v1 antibodies are permanent so ExpirySweep is no longer registered. | shared-cpu-1x | 512 MB | no | `DATABASE_URL`, `MORALIS_API_KEY` (optional) |
| `immunity-relayer` | `RELAYER` | Single process (`bin/relayer.php`). Polls `mirror.pending_jobs`, batches them, submits cross-chain mirror txs on Sepolia (and other configured destinations) via the Mirror contract. Postgres advisory lock prevents nonce collisions if two instances ever run. | shared-cpu-1x | 256 MB | no | `DATABASE_URL`, `SEPOLIA_RPC_URL`, `RELAYER_PRIVATE_KEY_SEPOLIA` |
| `immunity-db` | (Fly Managed Postgres) | The source of truth for everything except chain state. Schemas: `antibody`, `agent`, `event`, `network`, `indexer`, `mirror`, `demo`. Reached via `immunity-db.flycast:5432`. | (managed) | (managed) | n/a | n/a |
| `immunity-fleet` | (demo swarm) | Packed 60-agent showcase fleet + AXL spoke under supervisord. Lives in **`/Users/dtucker/www/immunity-demo`** (separate repo). Boot with `./scripts/fly-boot.sh`, fully shut down with `./scripts/fly-shutdown.sh`. Pause/resume of ambient activity is controlled from the admin tier of `/playground`. | shared-cpu-4x | 8 GB | no | `DATABASE_URL`, `MASTER_SEED`, `DEPLOYER_PRIVATE_KEY`, `MOCK_USDC_ADDRESS` |
| `immunity-hub-can`, `immunity-hub-usa` | (AXL hubs) | The two gossip hubs the spoke dials out to. Deployed from the `immunity-axl-hub` repo. Out of scope for this README. | n/a | n/a | n/a | n/a |

### Deploy + operate per app

Each app has its own `fly_<role>.toml` at the repo root:

```sh
fly deploy --config fly_app.toml      --app immunity-app      --remote-only
fly deploy --config fly_api.toml      --app immunity-api      --remote-only
fly deploy --config fly_indexer.toml  --app immunity-indexer  --remote-only
fly deploy --config fly_relayer.toml  --app immunity-relayer  --remote-only
```

The fleet deploys from the immunity-demo repo:
```sh
cd /Users/dtucker/www/immunity-demo
fly deploy --config fly_fleet.toml --app immunity-fleet --remote-only
./scripts/fly-boot.sh        # scale to 1 machine
./scripts/fly-shutdown.sh    # scale to 0 between sessions
```

Logs: `fly logs --app <name>`. Status: `fly status --app <name>`. Secrets: `fly secrets list --app <name>`.

### Playground gate

`/playground` is gated behind two passwords stored as Fly secrets on `immunity-app`:

- **`PLAYGROUND_PASSWORD`** — judge tier. Unlocks the page and the test-scenario buttons.
- **`ADMIN_PASSWORD`** — admin tier. Adds destructive ops + the pause/resume controls that toggle `demo.fleet_state.ambient_paused` (the flag every demo agent polls every tick).

Comparisons use `hash_equals` (constant time). If either secret is unset, the gate rejects every login (the `$expected === ''` short-circuit in `PlaygroundController`).

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
- Reorg policy is N=2 confirmations (testnet-grade). Mainnet would need a deeper window and a hash-based detector; revisit before mainnet deploy.
- ENS reverse resolution depends on a public Ethereum mainnet RPC and is best-effort. Failures back off for 24h.
- v1 antibodies are permanent: `expires_at` is stored on the row but never displayed or enforced. The `ExpirySweep` worker is no longer registered (the file is kept as dead code for v2).

## Three-tier lookup (Tier-2 mirror)

The SDK gates every `check()` against three tiers — local cache, on-chain Registry, then TEE detection. The on-chain tier resolves antibodies by **canonical primary matcher hash** via the Registry's `matcherIndex` state mapping. This app exposes the same lookup at the database and API level:

- **Schema.** `antibody.entry.primary_matcher_hash bytea`, partial-unique index for fast lookup. The indexer persists the hash from every `AntibodyPublished` event.
- **Broker.** `EntryBroker::findByPrimaryMatcherHash(string $hashHex)` accepts `0x`-prefixed or bare 64-hex strings, case-insensitive. Returns `null` for unknown or malformed input.
- **API.** `GET /api/antibody/by-matcher-hash/{hash}` returns the same envelope shape as `/api/antibody/{immId}` (entry + mirrors + recent blocks + publisher). 400 on malformed hex, 404 if the hash is unknown.

The matcher hash format is locked client-side by the SDK (`immunity-sdk/test/unit/keccak/matcher-format-parity.test.ts`); for reference, ADDRESS antibodies hash as `keccak256(abi.encode(uint256 chainId, address target))` and other types follow the per-type formats documented in the SDK's `docs/lookup-tiers.md`.

The contract enforces matcher uniqueness on publish: a second publisher claiming an existing matcher hash hits `AntibodyAlreadyExistsForMatcher(existingKeccakId)`, the SDK surfaces it as `MatcherAlreadyClaimedError`, and the explorer's downstream invariants (one antibody row per `primary_matcher_hash`) hold.

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
