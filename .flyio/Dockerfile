FROM php:8.4-apache-bookworm

# System packages: build tools, locales, supervisord, Postgres 15 server + client.
RUN apt-get update && apt-get install -y --no-install-recommends \
    git curl unzip zip locales supervisor ca-certificates \
    libonig-dev libcurl4-openssl-dev libsqlite3-dev libpq-dev libicu-dev \
    libxml2-dev zlib1g-dev libzip-dev libsodium-dev \
    postgresql postgresql-contrib postgresql-client \
    && rm -rf /var/lib/apt/lists/*

# Node.js 20 powers scripts/og-download.mjs (HydrationWorker) and
# scripts/mirror-send.mjs (RelayerWorker). PHP composer has no signing
# library so the relayer dispatches tx submission to ethers.js via proc_open.
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && rm -rf /var/lib/apt/lists/*

# Locale + timezone defaults.
ENV TZ="UTC"
RUN locale-gen en_US.UTF-8 && update-locale

# Postgres data directory lives on the Fly volume.
ENV PGDATA=/data/pgdata

# Composer.
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# PHP extensions Zephyrus + the app rely on.
RUN docker-php-ext-install pdo_pgsql pgsql intl mbstring sodium zip

# Production php.ini overrides.
COPY .flyio/php-production.ini /usr/local/etc/php/conf.d/zz-production.ini

# Apache modules.
RUN a2enmod rewrite headers expires deflate

# Apache vhost.
COPY .flyio/vhosts/default.conf /etc/apache2/sites-available/000-default.conf

# Composer install (cached layer).
COPY composer.json composer.lock /var/www/html/
ENV COMPOSER_ALLOW_SUPERUSER=1
RUN composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist --working-dir=/var/www/html

# App code.
COPY . /var/www/html

# Install Node helper deps (ethers, 0g-ts-sdk) into scripts/node_modules so the
# relayer / hydration workers can spawn them without a runtime npm install.
RUN cd /var/www/html/scripts \
    && npm install --legacy-peer-deps --omit=dev --no-audit --no-fund

# Writable directories.
RUN mkdir -p /var/www/html/cache/latte /var/www/html/temp \
    && chown -R www-data:www-data /var/www/html

# Supervisord config.
COPY .flyio/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Init script must be executable.
RUN chmod +x /var/www/html/.flyio/scripts/*.sh

EXPOSE 80

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
