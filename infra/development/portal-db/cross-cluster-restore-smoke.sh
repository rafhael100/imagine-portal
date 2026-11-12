#!/usr/bin/env bash
# F348 — second PostgreSQL 18 *cluster*, synthetic GitHub CI ONLY. Not a recovery procedure.
set -Eeuo pipefail
umask 077

[[ -v GITHUB_ACTIONS && "$GITHUB_ACTIONS" == true
   && -v PORTAL_DB_DISPOSABLE_CI && "$PORTAL_DB_DISPOSABLE_CI" == 1
   && -v COMPOSE_PROJECT_NAME && "$COMPOSE_PROJECT_NAME" == imagine-portal-foundation-ci
   && -v RUNNER_ENVIRONMENT && "$RUNNER_ENVIRONMENT" == github-hosted ]] || {
    printf 'Separate-cluster restore is only supported in disposable GitHub-hosted CI.\n' >&2
    exit 64
}
[[ -f ./compose.yaml && -f ./.private/database-admin ]] || exit 64

target=portal_identity_f348_restore_ci
temporary=$(mktemp -d)
container_id=''
cleanup() {
    local code=$?
    trap - EXIT
    if [[ -n "$container_id" ]]; then
        docker rm -f "$container_id" >/dev/null 2>&1 || code=1
    fi
    rm -rf -- "$temporary"
    exit "$code"
}
trap cleanup EXIT

source_pg() {
    timeout 180s docker compose exec -T --user 82:82 postgres sh -ec \
        'export PGPASSWORD="$(cat /run/secrets/database-admin)"; exec "$@"' sh "$@"
}
source_sql() {
    source_pg psql -X -h 127.0.0.1 -U postgres -d portal_identity_dev \
        -v ON_ERROR_STOP=1 -tA -c "$1"
}
empty_query='SELECT
 (SELECT count(*) FROM public.portal_identities) +
 (SELECT count(*) FROM public.portal_organizations) +
 (SELECT count(*) FROM public.portal_memberships) +
 (SELECT count(*) FROM public.portal_membership_grants) +
 (SELECT count(*) FROM public.portal_auth_sessions) +
 (SELECT count(*) FROM public.portal_auth_attempt_buckets) +
 (SELECT count(*) FROM public.portal_browser_envelopes) +
 (SELECT count(*) FROM public.portal_service_resource_claims)'
marker_query='SELECT marker FROM public.portal_development_installation WHERE singleton AND version=1'
[[ $(source_sql "$empty_query") == 0 ]] || exit 65
[[ $(source_sql 'SELECT count(*) FROM public.migrations') == 10 ]] || exit 65
source_marker=$(source_sql "$marker_query")
[[ "$source_marker" =~ ^[[:xdigit:]]{64}$ ]] || exit 65
# A key-count check is insufficient for HMAC continuity after recovery.
proof_digest_query="SELECT encode(public.digest(secret,'sha256'),'hex')
 FROM portal_browser_private.proof_key"
source_proof_digest=$(source_sql "$proof_digest_query")
[[ "$source_proof_digest" =~ ^[[:xdigit:]]{64}$ ]] || exit 65

source_pg pg_dump -h 127.0.0.1 -U postgres --format=custom portal_identity_dev \
    > "$temporary/source.dump"
[[ -s "$temporary/source.dump" ]] || exit 1
source_pg pg_restore --list < "$temporary/source.dump" > "$temporary/archive.list"
[[ -s "$temporary/archive.list" ]] || exit 1
[[ $(source_sql "$empty_query") == 0 && $(source_sql "$marker_query") == "$source_marker" ]] || exit 65
[[ $(source_sql "$proof_digest_query") == "$source_proof_digest" ]] || exit 65

# Separate clusters must not share an administrative credential, even in CI.
# The source admin secret is never copied into the recovered container.
target_admin="$temporary/recovery-admin"
openssl rand -hex 48 > "$target_admin"
[[ -s "$target_admin" ]] || exit 1
[[ ! -e "$PWD/.private/recovery-admin" ]] || exit 65
if cmp -s -- "$PWD/.private/database-admin" "$target_admin"; then
    printf 'Independent restore administrator password required.\\n' >&2
    exit 1
fi
# Official postgres:18-alpine starts as root and drops to its own postgres UID.
# Resolve that UID from the exact image instead of guessing from the source.
postgres_uid=$(docker run --rm --network none --entrypoint id postgres:18-alpine -u postgres)
[[ "$postgres_uid" =~ ^[0-9]{1,5}$ ]] || exit 1
chown "$postgres_uid:$postgres_uid" "$target_admin"
chmod 0400 "$target_admin"
[[ $(stat -c '%a' "$target_admin") == 400 ]] || exit 1

# New Docker container, independent cluster/volume, no network/host ports.
# Only the different, disposable target secret is mounted into it.
container_id=$(docker run --detach --network none --memory 1g --cpus 1 \
    --mount "type=bind,source=$target_admin,target=/run/secrets/database-admin,readonly" \
    --env POSTGRES_DB="$target" --env POSTGRES_USER=postgres \
    --env POSTGRES_PASSWORD_FILE=/run/secrets/database-admin \
    --env POSTGRES_INITDB_ARGS='--auth-host=scram-sha-256' \
    postgres:18-alpine)
[[ -n "$container_id" ]] || exit 1
[[ -z $(docker port "$container_id") ]] || exit 1
healthy=0
for attempt in {1..60}; do
    # pg_isready can succeed against initdb's *temporary* server before
    # POSTGRES_DB is created. Wait for the final PID 1 postgres process AND
    # a real SQL connection to the intended restored database.
    if docker exec "$container_id" sh -ec 'test "$(cat /proc/1/comm)" = postgres' 2>/dev/null \
       && docker exec --user postgres "$container_id" \
           psql -X -v ON_ERROR_STOP=1 -U postgres -d "$target" \
           -tAc 'SELECT current_database()' 2>/dev/null | grep -qx "$target"; then
        healthy=1
        break
    fi
    sleep 1
done
[[ "$healthy" == 1 ]] || exit 1

target_sql() {
    docker exec --user postgres "$container_id" psql -X -U postgres -d "$target" \
        -v ON_ERROR_STOP=1 -tA -c "$1"
}
# pg_dump does not contain global roles. Define only the exact, least-privilege
# owner/runtime names needed for restore; runtime roles stay NOLOGIN here.
docker exec -i --user postgres "$container_id" psql -X -U postgres -d "$target" -v ON_ERROR_STOP=1 <<'SQL' >/dev/null
CREATE ROLE portal_browser_owner NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
CREATE ROLE portal_browser_runtime NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
CREATE ROLE portal_browser_envelope_owner NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
CREATE ROLE portal_browser_envelope_runtime NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
SQL
target_sql "REVOKE CONNECT ON DATABASE $target FROM PUBLIC" >/dev/null
docker exec -i --user postgres "$container_id" pg_restore -U postgres \
    --dbname="$target" --exit-on-error < "$temporary/source.dump"

[[ $(target_sql "$marker_query") == "$source_marker" ]] || exit 1
[[ $(target_sql "$empty_query") == 0 ]] || exit 1
[[ $(target_sql 'SELECT count(*) FROM public.migrations') == 10 ]] || exit 1
# Reuse the same immutable function/migration contract in the new cluster.
docker exec -i --user postgres "$container_id" psql -X -U postgres \
    -d "$target" -v ON_ERROR_STOP=1 < ./restore-contracts.sql >/dev/null
[[ $(target_sql 'SELECT count(*) FROM portal_browser_private.proof_key') == 1 ]] || exit 1
[[ $(target_sql "$proof_digest_query") == "$source_proof_digest" ]] || exit 1
# Restored tables must keep their RLS and FORCE flags in a new cluster.
rls_query="SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
WHERE n.nspname||'.'||c.relname IN
('public.portal_identities','public.portal_auth_sessions',
 'public.portal_memberships','public.portal_membership_grants',
 'public.portal_organizations','public.portal_auth_attempt_buckets',
 'public.portal_browser_envelopes','public.portal_service_resource_claims',
 'portal_browser_private.proof_key') AND c.relrowsecurity AND c.relforcerowsecurity"
[[ $(target_sql "$rls_query") == 9 ]] || exit 1

[[ $(target_sql "SELECT count(*) FROM pg_roles WHERE
rolname IN ('portal_browser_owner','portal_browser_runtime',
'portal_browser_envelope_owner','portal_browser_envelope_runtime')
AND NOT (rolcanlogin OR rolsuper OR rolcreatedb OR rolcreaterole OR rolinherit OR rolbypassrls)") == 4 ]] || exit 1
[[ $(target_sql "SELECT pg_get_userbyid(proowner) FROM pg_proc WHERE
oid='public.portal_browser_active(text)'::regprocedure") == portal_browser_owner ]] || exit 1
[[ $(target_sql "SELECT pg_get_userbyid(proowner) FROM pg_proc WHERE
oid='public.portal_browser_envelope_read(text)'::regprocedure") == portal_browser_envelope_owner ]] || exit 1
# No runtime principal may obtain direct rights on sensitive restored tables.
privileges_query="SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
CROSS JOIN (VALUES ('portal_browser_runtime'),('portal_browser_envelope_runtime')) AS r(name)
WHERE n.nspname||'.'||c.relname IN
('public.portal_identities','public.portal_auth_sessions',
 'public.portal_memberships','public.portal_membership_grants',
 'public.portal_organizations','public.portal_auth_attempt_buckets',
 'public.portal_browser_envelopes','public.portal_service_resource_claims',
 'portal_browser_private.proof_key','public.portal_development_installation')
AND has_table_privilege(r.name,c.oid,
 'SELECT,INSERT,UPDATE,DELETE,TRUNCATE,REFERENCES,TRIGGER')"
[[ $(target_sql "$privileges_query") == 0 ]] || exit 1
# No default/public or third-party connection privilege should exist.
[[ $(target_sql "SELECT NOT EXISTS(SELECT 1 FROM pg_database d,
LATERAL aclexplode(coalesce(d.datacl,acldefault('d',d.datdba))) a
WHERE d.datname='$target' AND a.privilege_type='CONNECT'
AND a.grantee<>(SELECT oid FROM pg_roles WHERE rolname='postgres'))") == t ]] || exit 1
[[ $(source_sql "$marker_query") == "$source_marker" && $(source_sql "$empty_query") == 0 ]] || exit 1
[[ $(source_sql "$proof_digest_query") == "$source_proof_digest" ]] || exit 1
printf 'PASS F348 independent disposable cluster restore: RLS, owner roles and ACLs preserved.\n'
