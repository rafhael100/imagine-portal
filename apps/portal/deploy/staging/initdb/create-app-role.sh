#!/usr/bin/env bash
# F138: executes once on FIRST initialization of the staging DB data volume.
# Separate app role: no SUPERUSER, BYPASSRLS, CREATEDB or direct DML grants.
set -euo pipefail

: "${POSTGRES_USER:?}"
: "${POSTGRES_DB:?}"
: "${PORTAL_APP_DB_USER:?}"
: "${PORTAL_APP_DB_PASSWORD:?}"

if ! printf '%s' "$PORTAL_APP_DB_USER" |
    grep -Eq '^[a-z][a-z0-9_]{2,30}$'; then
    echo 'F138: invalid app role identifier' >&2
    exit 1
fi
if [[ "$PORTAL_APP_DB_USER" == "$POSTGRES_USER" ||
      "${#PORTAL_APP_DB_PASSWORD}" -lt 20 ]]; then
    echo 'F138: app DB role must differ from owner and use a strong secret' >&2
    exit 1
fi

psql -X -v ON_ERROR_STOP=1 \
  --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" \
  -v app_role="$PORTAL_APP_DB_USER" \
  -v app_password="$PORTAL_APP_DB_PASSWORD" <<'SQL'
SELECT format(
  'CREATE ROLE %I LOGIN PASSWORD %L NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOINHERIT',
  :'app_role', :'app_password'
) \gexec
SELECT format('GRANT USAGE ON SCHEMA public TO %I', :'app_role') \gexec
SELECT format(
  'GRANT CONNECT ON DATABASE %I TO %I',
  current_database(), :'app_role'
) \gexec
SQL

echo 'F138: restricted Portal runtime PostgreSQL role provisioned (no DML grants)'
