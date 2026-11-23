FROM ghcr.io/zephyrus-framework/php:8.4-dev

# Dev-only Apache and PHP settings, carried over from the former root .htaccess
# (Apache never reads a .htaccess above public/ on this image).
COPY immunity.conf /etc/apache2/conf-available/immunity.conf
RUN a2enconf immunity

# Supercronic - used by the cron service in docker-compose to run the crontab.
# Same binary as the production .flyio/Dockerfile.app.
ARG SUPERCRONIC_VERSION=0.2.30
RUN set -e; \
    arch="$(dpkg --print-architecture)"; \
    case "$arch" in \
      amd64) sc_arch="amd64" ;; \
      arm64) sc_arch="arm64" ;; \
      *) echo "Unsupported arch: $arch" >&2; exit 1 ;; \
    esac; \
    curl -fsSL -o /usr/local/bin/supercronic \
      "https://github.com/aptible/supercronic/releases/download/v${SUPERCRONIC_VERSION}/supercronic-linux-${sc_arch}"; \
    chmod +x /usr/local/bin/supercronic

# Node 20 for the indexer's 0G Storage hydration shell-out helper.
# The PHP HydrationWorker spawns `node scripts/og-download.mjs <cid>` per
# pending job; the SDK is TypeScript-only so we run it via Node. The
# indexer service installs scripts/node_modules at startup against the
# volume-mounted scripts/package.json (`npm install` is idempotent).
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
 && apt-get install -y --no-install-recommends nodejs \
 && rm -rf /var/lib/apt/lists/*
