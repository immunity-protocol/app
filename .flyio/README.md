# Immunity Fly.io Deployment

Provisioning and deployment guide for the Immunity stack on Fly.io.

Apps (CodeQuill-style three-app shape):
- `immunity-db` - Fly **managed Postgres cluster** (created via `fly pg create`, no app config in this repo)
- `immunity-app` - WEB tier serving the public site at `immunity-protocol.com` plus the internal `/api/v1/*` endpoints the home JS polls
- `immunity-api` - API tier with two processes from the same image:
  - `app` process: serves the public developer surface at `api.immunity-protocol.com/v1/*` plus the cron-only `/v1/internal/tick`
  - `cron` process: supercronic, calls `immunity-api.internal/v1/internal/tick` once per minute

## Prerequisites

- `flyctl` installed and authenticated:
  ```bash
  fly auth login
  ```
- Access to the Fly organization (example: `ophelios`)

## 1) Create the managed Postgres cluster

```bash
fly pg create \
  --name immunity-db \
  --region yyz \
  --initial-cluster-size 1 \
  --vm-size shared-cpu-1x \
  --volume-size 5
```

Record the connection string output at the end (`postgres://postgres:<password>@immunity-db.flycast:5432`). It is needed in step 3.

## 2) Launch the two Fly apps (no deploy yet)

```bash
fly launch -c fly_app.toml --name immunity-app --region yyz --no-deploy --org ophelios
fly launch -c fly_api.toml --name immunity-api --region yyz --no-deploy --org ophelios
```

## 3) Set secrets

Generate a cron token shared between immunity-api's `app` process (verifier) and `cron` process (caller):
```bash
CRON_TOKEN="$(openssl rand -hex 32)"
DATABASE_URL='postgres://postgres:<password>@immunity-db.flycast:5432/immunity'
```

```bash
fly secrets set DATABASE_URL="$DATABASE_URL" --app immunity-app
fly secrets set DATABASE_URL="$DATABASE_URL" CRON_TOKEN="$CRON_TOKEN" --app immunity-api
```

The `app` tier does not need `CRON_TOKEN`; only the API tier does (its `app` process verifies it on `/v1/internal/tick`, and its `cron` process sends it).

## 4) Deploy each app

```bash
fly deploy -c fly_app.toml
fly deploy -c fly_api.toml
```

`fly_api.toml` defines two processes (`app` + `cron`) so a single deploy spins up both an Apache machine and a supercronic machine.

## 5) Initialize the schema and seed mock data

The Fly managed Postgres cluster ships with the cluster's bookkeeping `postgres` database but not the application's `immunity` database. Create it then load the schema + mock data:

```bash
fly ssh console --app immunity-api
psql 'postgres://postgres:<password>@immunity-db.flycast:5432/postgres' -c 'CREATE DATABASE immunity;'
php /var/www/html/bin/init-database.php
php /var/www/html/bin/seed.php --reset --small
exit
```

`bin/init-database.php` drops + recreates the database and runs `sql/0-init-database.sql`, which `\ir`-includes every per-domain `structure.sql`. `bin/seed.php --reset --small` then truncates and repopulates with a 30-antibody / 1k-event demo dataset that takes ~80s to write across the Fly Postgres link. Drop `--small` for the full 350-antibody / 50k-event set if you have ~70 minutes to wait (sequential inserts are bottlenecked by the round-trip).

## 6) Smoke test

```bash
curl https://immunity-app.fly.dev/health
curl https://immunity-app.fly.dev/api/v1/network/stats
curl https://immunity-api.fly.dev/v1/antibodies?limit=3
curl https://immunity-api.fly.dev/v1/feed/antibodies.json | head -c 200
curl -X POST -H "X-CRON-TOKEN: $CRON_TOKEN" https://immunity-api.fly.dev/v1/internal/tick
fly logs -a immunity-api | grep -E "supercronic|tick" | tail -5
```

## 7) Custom domains (when DNS is ready)

```bash
fly certs add immunity-protocol.com -a immunity-app
fly certs add api.immunity-protocol.com -a immunity-api
```

For the apex (`immunity-protocol.com`):
- `A` record pointing at the IPv4 Fly returns from `fly ips list -a immunity-app`
- `AAAA` record pointing at the IPv6
- (CNAME flattening at the registrar also works if your DNS provider supports it)

For the subdomain (`api.immunity-protocol.com`): a CNAME to `immunity-api.fly.dev` is the cleanest.

## Maintenance

### Status
```bash
fly status --app immunity-app
fly status --app immunity-api
fly machines list -a immunity-api   # see both [app] and [cron] process groups
fly pg list                          # for the managed cluster
```

### SSH into a machine
```bash
fly ssh console --app immunity-app
fly ssh console --app immunity-api -s   # pick app or cron process when prompted
```

### Reseed mock data
```bash
fly ssh console --app immunity-api
php /var/www/html/bin/seed.php --reset --small
```

### Rotate the cron token
```bash
NEW_TOKEN="$(openssl rand -hex 32)"
fly secrets set CRON_TOKEN="$NEW_TOKEN" --app immunity-api
# both processes (app + cron) inside immunity-api roll automatically when secrets land
```

## Danger zone: drop and recreate the database

```bash
fly ssh console --app immunity-api
PGPASSWORD="<password>" psql -h immunity-db.flycast -p 5432 -U postgres -d postgres \
  -c "DROP DATABASE immunity WITH (FORCE);"
PGPASSWORD="<password>" psql -h immunity-db.flycast -p 5432 -U postgres -d postgres \
  -c "CREATE DATABASE immunity;"
php /var/www/html/bin/init-database.php
php /var/www/html/bin/seed.php --reset --small
```

## Troubleshooting

### Cron is not hitting the API
Check the cron process logs (the cron machine is part of immunity-api, process group `cron`):
```bash
fly machines list -a immunity-api
fly logs -a immunity-api | grep -E "supercronic|tick"
```

### LIVE pulse on the home page is amber/red
Means `network.stat` rows are stale. Either the cron process has stopped or the API tier is down. Check both:
```bash
fly status -a immunity-api
fly machines list -a immunity-api    # confirm a cron-process machine is in 'started' state
curl -X POST -H "X-CRON-TOKEN: $CRON_TOKEN" https://immunity-api.fly.dev/v1/internal/tick
```
