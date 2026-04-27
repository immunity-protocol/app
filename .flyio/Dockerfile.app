# Image used by the WEB / API / CRON tiers (immunity-web, immunity-api, immunity-cron).
# No bundled Postgres - the database lives in immunity-db.
FROM php:8.4-apache-bookworm

# System packages: build tools, locales, supercronic for the CRON tier.
RUN apt-get update && apt-get install -y --no-install-recommends \
    git curl unzip zip locales ca-certificates \
    libonig-dev libcurl4-openssl-dev libsqlite3-dev libpq-dev libicu-dev \
    libxml2-dev zlib1g-dev libzip-dev libsodium-dev \
    postgresql-client \
    && rm -rf /var/lib/apt/lists/*

# Locale + timezone defaults.
ENV TZ="UTC"
RUN locale-gen en_US.UTF-8 && update-locale

# Composer.
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# PHP extensions.
RUN docker-php-ext-install pdo_pgsql pgsql intl mbstring sodium zip

# Production php.ini overrides (shared with the legacy image).
COPY .flyio/php-production.ini /usr/local/etc/php/conf.d/zz-production.ini

# Apache modules + vhost.
RUN a2enmod rewrite headers expires deflate
COPY .flyio/vhosts/default.conf /etc/apache2/sites-available/000-default.conf

# Composer install (cached layer).
COPY composer.json composer.lock /var/www/html/
ENV COMPOSER_ALLOW_SUPERUSER=1
RUN composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist --working-dir=/var/www/html || true

# App code.
COPY . /var/www/html

# Writable directories.
RUN mkdir -p /var/www/html/cache/latte /var/www/html/temp \
    && chown -R www-data:www-data /var/www/html

# Supercronic (used only by the CRON tier; harmless on web/api).
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


# Node 20 + scripts/ Node deps for the indexer's 0G Storage hydration helper.
# Harmless on web/api (those tiers never invoke node).
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
 && apt-get install -y --no-install-recommends nodejs \
 && rm -rf /var/lib/apt/lists/*
RUN cd /var/www/html/scripts \
 && npm install --legacy-peer-deps --omit=dev --no-audit --no-fund

EXPOSE 80

# Default to Apache foreground; the CRON tier overrides this in fly_cron.toml.
CMD ["apache2-foreground"]
