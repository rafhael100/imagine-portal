FROM php:8.4-cli-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends git unzip libpq-dev libonig-dev libicu-dev libzip-dev \
    && docker-php-ext-install -j2 pdo_pgsql mbstring intl zip \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html
ENV COMPOSER_ALLOW_SUPERUSER=1
COPY apps/portal/composer.json apps/portal/composer.lock ./
RUN composer install --no-interaction --no-progress --prefer-dist --no-scripts --no-plugins
COPY apps/portal/app ./app
COPY apps/portal/bootstrap ./bootstrap
COPY apps/portal/config ./config
COPY apps/portal/public ./public
COPY apps/portal/resources ./resources
COPY apps/portal/routes ./routes
COPY apps/portal/database ./database
COPY apps/portal/tests ./tests
COPY apps/portal/artisan apps/portal/phpunit.xml ./
COPY apps/portal/infra/browser-auth-lab/run.sh /usr/local/bin/portal-browser-rehearsal
RUN composer dump-autoload --no-interaction --no-scripts --no-plugins --classmap-authoritative \
    && chmod 0555 /usr/local/bin/portal-browser-rehearsal
USER www-data
ENTRYPOINT ["/bin/sh","/usr/local/bin/portal-browser-rehearsal"]
