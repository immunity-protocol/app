FROM ghcr.io/ophelios-studio/docker-pulsar-php84:latest

# The Pulsar image ships with both 000-default.conf (DocumentRoot /var/www/html)
# and default.conf (DocumentRoot /var/www/html/public). Disable the conflicting
# default so Apache serves from /var/www/html/public as expected.
RUN a2dissite 000-default.conf 2>/dev/null || true

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
