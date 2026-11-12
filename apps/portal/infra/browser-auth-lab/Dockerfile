FROM php:8.4-cli-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends git unzip libpq-dev libonig-dev libicu-dev libzip-dev \
    && docker-php-ext-install -j2 pdo_pgsql mbstring intl zip \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html
ENV COMPOSER_ALLOW_SUPERUSER=1
COPY apps/portal/composer.json apps/portal/composer.lock ./
RUN composer install --no-interaction --no-progress --prefer-dist --no-scripts --no-plugins --no-autoloader
COPY apps/portal/app ./app
COPY apps/portal/bootstrap/app.php apps/portal/bootstrap/providers.php ./bootstrap/
COPY apps/portal/config ./config
COPY apps/portal/public ./public
COPY apps/portal/resources ./resources
COPY apps/portal/routes ./routes
COPY apps/portal/database/migrations ./database/migrations
COPY apps/portal/database/security ./database/security
COPY apps/portal/tests/TestCase.php ./tests/TestCase.php
COPY apps/portal/tests/Feature/PortalBrowserAuthTransportTest.php apps/portal/tests/Feature/PortalDefaultDenyAuthorityBindingsTest.php ./tests/Feature/
COPY apps/portal/tests/Integration/PortalBrowserAuthenticationPg18Test.php apps/portal/tests/Integration/PortalBrowserEnvelopePg18Test.php ./tests/Integration/
COPY apps/portal/tests/Support/BrowserMfaRaceWorker.php apps/portal/tests/Support/BrowserEnvelopeRaceWorker.php ./tests/Support/
COPY apps/portal/artisan apps/portal/phpunit.xml ./
COPY apps/portal/infra/browser-auth-lab/run.sh /usr/local/bin/portal-browser-rehearsal
RUN composer dump-autoload --no-interaction --no-scripts --no-plugins --classmap-authoritative \
    && chmod 0555 /usr/local/bin/portal-browser-rehearsal
USER www-data
ENTRYPOINT ["/bin/sh","/usr/local/bin/portal-browser-rehearsal"]
