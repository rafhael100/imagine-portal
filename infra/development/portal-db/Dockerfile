FROM php:8.4-cli-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev libicu-dev libzip-dev libonig-dev unzip \
    && docker-php-ext-install -j2 pdo_pgsql intl zip mbstring bcmath \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html
COPY apps/portal/composer.json apps/portal/composer.lock ./
RUN COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --no-plugins
COPY apps/portal/app ./app
COPY apps/portal/bootstrap ./bootstrap
COPY apps/portal/config ./config
COPY apps/portal/routes ./routes
COPY apps/portal/public ./public
COPY apps/portal/resources ./resources
COPY apps/portal/database/migrations ./database/migrations
COPY apps/portal/database/security/portal_browser_auth.sql apps/portal/database/security/portal_browser_envelopes.sql ./database/security/
COPY apps/portal/artisan ./
COPY infra/development/portal-db/provision.php ./
RUN rm -rf app/Labs bootstrap/cache/* \
    && COMPOSER_ALLOW_SUPERUSER=1 composer dump-autoload --no-dev --no-interaction --no-scripts --no-plugins --classmap-authoritative \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
USER 82:82
ENTRYPOINT []
CMD ["php","provision.php","verify"]
