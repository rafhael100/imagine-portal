# First online laboratory build; NOT offline/reproducible APT closure. No runtime activation.
ARG PHP_BASE
ARG COMPOSER_BASE
FROM ${COMPOSER_BASE} AS composer
FROM ${PHP_BASE}
ARG PHP_PACKAGE_VERSION=8.4.27-1~deb13u1
RUN set -eu; \
 test "$(dpkg --print-architecture)" = amd64; \
 . /etc/os-release; test "$ID" = debian; test "$VERSION_CODENAME" = trixie; \
 test -r /usr/share/keyrings/debian-archive-keyring.gpg; \
 rm -f /etc/apt/sources.list /etc/apt/sources.list.d/* /etc/apt/apt.conf.d/docker-clean; \
 printf '%s\n' 'Types: deb' 'URIs: http://deb.debian.org/debian' 'Suites: trixie trixie-updates' 'Components: main' 'Signed-By: /usr/share/keyrings/debian-archive-keyring.gpg' '' 'Types: deb' 'URIs: http://deb.debian.org/debian-security' 'Suites: trixie-security' 'Components: main' 'Signed-By: /usr/share/keyrings/debian-archive-keyring.gpg' > /etc/apt/sources.list.d/debian.sources; \
 apt-get -o Acquire::AllowInsecureRepositories=false -o APT::Get::AllowUnauthenticated=false update; \
 DEBIAN_FRONTEND=noninteractive apt-get -o APT::Keep-Downloaded-Packages=true -o Acquire::AllowInsecureRepositories=false -o APT::Get::AllowUnauthenticated=false install -y --no-install-recommends \
 ca-certificates "php8.4-cli=$PHP_PACKAGE_VERSION" "php8.4-common=$PHP_PACKAGE_VERSION" "php8.4-pgsql=$PHP_PACKAGE_VERSION" "php8.4-mbstring=$PHP_PACKAGE_VERSION" "php8.4-xml=$PHP_PACKAGE_VERSION" "php8.4-curl=$PHP_PACKAGE_VERSION" "php8.4-zip=$PHP_PACKAGE_VERSION"; \
 for p in cli common pgsql mbstring xml curl zip; do test "$(dpkg-query -W -f='${Version}' php8.4-$p)" = "$PHP_PACKAGE_VERSION"; done; \
 mkdir -p /usr/local/share/onepage-build; \
 dpkg-query -W -f='${Package}\t${Version}\t${Architecture}\n' > /usr/local/share/onepage-build/packages.txt; \
 find /var/cache/apt/archives -name '*.deb' -type f -exec sha256sum '{}' ';' | sort > /usr/local/share/onepage-build/debs.sha256; \
 test -s /usr/local/share/onepage-build/debs.sha256; \
 find /var/lib/apt/lists -maxdepth 1 -type f -exec sha256sum '{}' ';' | sort > /usr/local/share/onepage-build/apt-indexes.sha256; \
 php -r 'if (!extension_loaded("pdo_pgsql") || PHP_VERSION_ID < 80400 || PHP_VERSION_ID >= 80500) exit(1);'; \
 php --version > /usr/local/share/onepage-build/php-version.txt; \
 php --modules > /usr/local/share/onepage-build/php-modules.txt; \
 sha256sum /usr/bin/php8.4 /usr/lib/php/*/pdo_pgsql.so > /usr/local/share/onepage-build/php-bytes.sha256
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
WORKDIR /opt/onepage/application
COPY application/ ./
RUN COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --no-scripts --no-plugins --prefer-dist --no-interaction --classmap-authoritative \
 && composer check-platform-reqs --no-dev \
 && composer show --locked --no-dev --format=json > /usr/local/share/onepage-build/composer-locked.json \
 && sha256sum composer.lock /usr/local/bin/composer > /usr/local/share/onepage-build/composer.sha256 \
 && find vendor -type f -exec sha256sum '{}' ';' | sort > /usr/local/share/onepage-build/vendor.sha256
COPY worker/ /opt/onepage/worker/
RUN php -r 'require "/opt/onepage/worker/load.php";'
USER 65534:65534
ENTRYPOINT ["php", "/opt/onepage/worker/entry.php"]
