FROM composer@sha256:0baf37e7f495f1c42cee6bdd9cf3e00e9435f193b512529e7c484d66c623773f AS composer
FROM postgres@sha256:12060a1464f232e4f8c721538b342687c6f17cead32a389586cd683262b1d848
USER root
ARG PHP_PACKAGE_VERSION=8.4.28-1~deb13u1
RUN set -eu; test "$(dpkg --print-architecture)" = amd64; . /etc/os-release; test "$ID" = debian; test "$VERSION_CODENAME" = trixie; \
 /usr/lib/postgresql/18/bin/postgres --version | grep -F '18.7'; \
 rm -f /etc/apt/sources.list /etc/apt/sources.list.d/* /etc/apt/apt.conf.d/docker-clean; \
 printf '%s\n' 'Types: deb' 'URIs: http://deb.debian.org/debian' 'Suites: trixie trixie-updates' 'Components: main' 'Signed-By: /usr/share/keyrings/debian-archive-keyring.gpg' '' 'Types: deb' 'URIs: http://deb.debian.org/debian-security' 'Suites: trixie-security' 'Components: main' 'Signed-By: /usr/share/keyrings/debian-archive-keyring.gpg' > /etc/apt/sources.list.d/debian.sources; \
 apt-get -o Acquire::AllowInsecureRepositories=false -o APT::Get::AllowUnauthenticated=false update; \
 DEBIAN_FRONTEND=noninteractive apt-get -o APT::Keep-Downloaded-Packages=true -o APT::Get::AllowUnauthenticated=false install -y --no-install-recommends python3 ca-certificates \
 "php8.4-cli=$PHP_PACKAGE_VERSION" "php8.4-common=$PHP_PACKAGE_VERSION" "php8.4-pgsql=$PHP_PACKAGE_VERSION" "php8.4-mbstring=$PHP_PACKAGE_VERSION" "php8.4-xml=$PHP_PACKAGE_VERSION" "php8.4-curl=$PHP_PACKAGE_VERSION" "php8.4-zip=$PHP_PACKAGE_VERSION"; \
 for p in cli common pgsql mbstring xml curl zip; do test "$(dpkg-query -W -f='${Version}' php8.4-$p)" = "$PHP_PACKAGE_VERSION"; done; \
 php -r 'if (!extension_loaded("pdo_pgsql") || !extension_loaded("posix")) exit(1);'; \
 mkdir -p /opt/onepage/evidence; \
 dpkg-query -W -f='${Package}\t${Version}\t${Architecture}\n' > /opt/onepage/evidence/packages.txt; \
 find /var/cache/apt/archives -name '*.deb' -type f -exec sha256sum '{}' ';' | sort > /opt/onepage/evidence/debs.sha256; \
 test -s /opt/onepage/evidence/debs.sha256; \
 find /var/lib/apt/lists -maxdepth 1 -type f -exec sha256sum '{}' ';' | sort > /opt/onepage/evidence/apt.sha256
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY runtime /opt/onepage/runtime
WORKDIR /opt/onepage/runtime/application
RUN COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --no-scripts --no-plugins --prefer-dist --no-interaction --classmap-authoritative \
 && composer check-platform-reqs --no-dev \
 && find vendor -type f -exec sha256sum '{}' ';' | sort > /opt/onepage/evidence/vendor.sha256
COPY supervisor.py /opt/onepage/supervisor.py
# Host stays private (umask 077); only immutable image directories become traversable.
RUN find /opt/onepage/runtime -type d -exec chmod 0755 '{}' ';' \
 && find /opt/onepage/runtime -type f -exec chmod go-w '{}' ';'
USER postgres
RUN python3 -c 'import os,pathlib,pwd; r=pathlib.Path("/opt/onepage/runtime"); h=r/"local-harness/run.php"; assert pwd.getpwuid(os.geteuid()).pw_name=="postgres"; h.open("rb").read(1); paths=[r]; [(paths.extend(pathlib.Path(d)/n for n in dirs+files)) for d,dirs,files in os.walk(r,onerror=lambda e: (_ for _ in ()).throw(e))]; assert all(not os.access(p,os.W_OK) for p in paths); assert all(os.access(p,os.R_OK | (os.X_OK if p.is_dir() else 0)) for p in paths); print("PASS IMAGE_POSTGRES_READ_ONLY_RUNTIME")' 
ENTRYPOINT ["python3", "/opt/onepage/supervisor.py"]
