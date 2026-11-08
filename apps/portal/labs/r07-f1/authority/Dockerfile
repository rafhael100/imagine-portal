FROM php:8.4-cli-alpine
RUN apk add --no-cache postgresql-dev && docker-php-ext-install pdo_pgsql
WORKDIR /srv/r07
COPY router.php /srv/r07/router.php
USER www-data
CMD ["php", "-S", "0.0.0.0:8080", "-t", "/srv/r07", "/srv/r07/router.php"]
