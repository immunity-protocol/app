# Immunity Fly.io Deployment

Provisioning and deployment guide for the Immunity stack on Fly.io.

Apps:
- `immunity-db` - Fly **managed Postgres cluster** (created via `fly pg create`, no app config in this repo)
- `immunity-web` - WEB tier serving the public site at `immunity-protocol.com` plus the internal `/api/v1/*` endpoints the home JS polls
- `immunity-api` - API tier serving the public developer surface at `api.immunity-protocol.com/v1/*` plus the cron-only `/v1/internal/tick`
- `immunity-cron` - CRON tier running supercronic; calls `immunity-api.internal/v1/internal/tick` once per minute

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

Record the connection string output at the end (`postgres://immunity:<password>@immunity-db.flycast:5432/immunity`). It is needed in step 3.

## 2) Launch the three Fly apps (no deploy yet)

```bash
fly launch -c fly_web.toml  --name immunity-web  --region yyz --no-deploy --org ophelios
fly launch -c fly_api.toml  --name immunity-api  --region yyz --no-deploy --org ophelios
fly launch -c fly_cron.toml --name immunity-cron --region yyz --no-deploy --org ophelios
```

## 3) Set secrets

Generate a cron token shared between immunity-api (verifier) and immunity-cron (caller):
```bash
CRON_TOKEN="$(openssl rand -hex 32)"
DATABASE_URL='postgres://immunity:<password>@immunity-db.flycast:5432/immunity'
```

```bash
fly secrets set DATABASE_URL="$DATABASE_URL" CRON_TOKEN="$CRON_TOKEN" --app immunity-web
fly secrets set DATABASE_URL="$DATABASE_URL" CRON_TOKEN="$CRON_TOKEN" --app immunity-api
fly secrets set CRON_TOKEN="$CRON_TOKEN" --app immunity-cron
```

The cron tier does not need DB access; it only POSTs to the API tier.

## 4) Deploy each app

```bash
fly deploy -c fly_web.toml
fly deploy -c fly_api.toml
fly deploy -c fly_cron.toml
```

## 5) Initialize the schema and seed mock data

The Fly managed Postgres cluster ships an empty `immunity` database. Connect to the web machine and load the schema + mock data:

```bash
fly ssh console --app immunity-web
php /var/www/html/bin/init-database.php
php /var/www/html/bin/seed.php --reset
exit
```

`bin/init-database.php` drops + recreates the database and runs `sql/0-init-database.sql`, which `\ir`-includes every per-domain `structure.sql`. `bin/seed.php --reset` then truncates and repopulates with reproducible mock data (350 antibodies, 50k check events, etc.).

## 6) Smoke test

```bash
curl https://immunity-web.fly.dev/health
curl https://immunity-web.fly.dev/api/v1/network/stats
curl https://immunity-api.fly.dev/v1/antibodies?limit=3
curl https://immunity-api.fly.dev/v1/feed/antibodies.json | head -c 200
curl -X POST -H "X-CRON-TOKEN: $CRON_TOKEN" https://immunity-api.fly.dev/v1/internal/tick
fly logs -a immunity-cron   # confirm cron is hitting the tick endpoint every minute
```

## 7) Custom domains (when DNS is ready)

```bash
fly certs add immunity-protocol.com -a immunity-web
fly certs add api.immunity-protocol.com -a immunity-api
```

For the apex (`immunity-protocol.com`):
- `A` record pointing at the IPv4 Fly returns from `fly ips list -a immunity-web`
- `AAAA` record pointing at the IPv6
- (CNAME flattening at the registrar also works if your DNS provider supports it)

For the subdomain (`api.immunity-protocol.com`): a CNAME to `immunity-api.fly.dev` is the cleanest.

## Maintenance

### Status
```bash
fly status --app immunity-web
fly status --app immunity-api
fly status --app immunity-cron
fly pg list   # for the managed cluster
```

### SSH into a machine
```bash
fly ssh console --app immunity-web
```

### Reseed mock data
```bash
fly ssh console --app immunity-web
php /var/www/html/bin/seed.php --reset
```

### Rotate the cron token
```bash
NEW_TOKEN="$(openssl rand -hex 32)"
fly secrets set CRON_TOKEN="$NEW_TOKEN" --app immunity-api
fly secrets set CRON_TOKEN="$NEW_TOKEN" --app immunity-cron
# both apps roll automatically when secrets land
```

## Danger zone: drop and recreate the database

```bash
fly ssh console --app immunity-web
PGPASSWORD="<password>" psql -h immunity-db.flycast -p 5432 -U immunity -d postgres \
  -c "DROP DATABASE immunity WITH (FORCE);"
PGPASSWORD="<password>" psql -h immunity-db.flycast -p 5432 -U immunity -d postgres \
  -c "CREATE DATABASE immunity;"
php /var/www/html/bin/init-database.php
php /var/www/html/bin/seed.php --reset
```

## Troubleshooting

### Cron is not hitting the API
Check the cron container logs and confirm `CRON_TOKEN` is set:
```bash
fly logs -a immunity-cron
fly ssh console -a immunity-cron
echo $CRON_TOKEN   # should be the same value as on immunity-api
```

### LIVE pulse on the home page is amber/red
Means `network.stat` rows are stale. Either the cron has stopped or the API tier is down. Check both:
```bash
fly status -a immunity-api
fly status -a immunity-cron
curl -X POST -H "X-CRON-TOKEN: $CRON_TOKEN" https://immunity-api.fly.dev/v1/internal/tick
```
