# F138: disposable-image build for MANUALLY APPROVED VPS staging only.
# No APP_KEY, credential, database dump or client data belongs in an image.
FROM php:8.4-fpm-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates libpq-dev libicu-dev libzip-dev libonig-dev unzip \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql intl mbstring zip bcmath opcache \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html

# Install only immutable production dependencies. Never execute arbitrary
# composer plugins or project scripts during dependency resolution.
COPY composer.json composer.lock ./
RUN composer install \
    --no-dev --no-interaction --no-progress --prefer-dist \
    --no-scripts --no-plugins --no-autoloader

COPY . .
COPY --from=performance_engine src/Domain/Administration/AdministrativeLabEngine.php /opt/imagine/performance-engine/src/Domain/Administration/AdministrativeLabEngine.php
COPY --from=performance_engine src/Domain/Commercial/MonthlyManagementFeeFloorV7Candidate.php /opt/imagine/performance-engine/src/Domain/Commercial/MonthlyManagementFeeFloorV7Candidate.php
COPY --from=performance_engine src/Domain/Administration/AdministrativeServiceEnvelope.php /opt/imagine/performance-engine/src/Domain/Administration/AdministrativeServiceEnvelope.php
RUN composer dump-autoload \
        --no-dev --classmap-authoritative --no-scripts --no-plugins \
    && mkdir -p \
        storage/framework/sessions storage/framework/views \
        storage/framework/cache/data storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

USER www-data
EXPOSE 9000
CMD ["php-fpm", "-F"]

