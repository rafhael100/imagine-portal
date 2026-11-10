# syntax=docker/dockerfile:1
# PORTAL-F64: private smoke/homologation image, not a production release.
FROM php:8.4-fpm-alpine

RUN apk add --no-cache icu-libs libzip libpq oniguruma libxml2 \
    && apk add --no-cache --virtual .portal-build-deps \
       $PHPIZE_DEPS icu-dev libzip-dev postgresql-dev oniguruma-dev libxml2-dev \
    && docker-php-ext-install -j"$(nproc)" bcmath intl mbstring pdo_pgsql zip opcache \
    && apk del .portal-build-deps \
    && php -m | grep -Fx 'pdo_pgsql' >/dev/null

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html

# A frozen lockfile, with all plugins/scripts disabled. No real secrets here.
COPY apps/portal/composer.json apps/portal/composer.lock ./
RUN COMPOSER_ALLOW_SUPERUSER=1 composer install \
      --no-interaction --no-progress --prefer-dist --no-dev \
      --no-scripts --no-plugins --classmap-authoritative \
    && rm -rf /root/.composer /root/.cache

# Only application runtime files, not lab migrations, tests, CI or docs.
COPY apps/portal/app ./app
COPY apps/portal/bootstrap ./bootstrap
COPY apps/portal/config ./config
COPY apps/portal/public ./public
COPY apps/portal/resources ./resources
COPY apps/portal/routes ./routes
COPY apps/portal/artisan ./artisan

# Exclude identity labs AND strictly test-only offline report consumers.
# In particular, no synthetic PostgreSQL superuser or MFA fixture helper
# should be present or Composer-autoloadable even in staging.
RUN rm -rf app/Labs \
    && rm -f app/Domain/PerformanceDashboard/OfflinePerformanceWorkbenchPreview.php \
      app/Domain/PerformanceDashboard/CredentialBoundPerformanceWorkbenchPreview.php \
      app/Domain/SeoReview/OfflineSeoHandoffPreview.php \
    && test ! -d app/Labs \
    && test ! -e app/Domain/PerformanceDashboard/OfflinePerformanceWorkbenchPreview.php \
    && test ! -e app/Domain/PerformanceDashboard/CredentialBoundPerformanceWorkbenchPreview.php \
    && test ! -e app/Domain/SeoReview/OfflineSeoHandoffPreview.php \
    && COMPOSER_ALLOW_SUPERUSER=1 composer dump-autoload \
      --no-interaction --no-scripts --no-plugins --classmap-authoritative \
    && mkdir -p storage/framework/cache/data storage/framework/sessions \
       storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R u=rwX,go= storage bootstrap/cache

ENV APP_ENV=staging \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    SESSION_DRIVER=array \
    CACHE_STORE=array \
    QUEUE_CONNECTION=sync

# FPM listens only on the isolated Docker network, behind health-only nginx.
USER www-data
EXPOSE 9000
CMD ["php-fpm", "-F"]
