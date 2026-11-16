FROM php:8.4-cli-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends libonig-dev libpq-dev unzip && docker-php-ext-install mbstring pdo_pgsql && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-plugins --prefer-dist --no-interaction --no-progress
COPY . .
RUN composer dump-autoload --no-dev --no-scripts --no-plugins && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views bootstrap/cache && chown -R www-data:www-data storage bootstrap/cache
USER www-data
ENTRYPOINT ["php","tests/Integration/AutomationS2S/read-private.php"]
