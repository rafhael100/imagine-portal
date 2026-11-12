#!/usr/bin/env bash
# F338 — disposable, same-cluster logical restore drill. NEVER production.
set -Eeuo pipefail
umask 077

[[ ${GITHUB_ACTIONS:-} == true
   && ${PORTAL_DB_DISPOSABLE_CI:-} == 1
   && ${COMPOSE_PROJECT_NAME:-} == imagine-portal-foundation-ci ]] || {
    printf 'Disposable CI context required.\n' >&2
    exit 64
}
[[ -f ./compose.yaml && -f ./provision.php ]] || exit 64

target=portal_identity_f338_restore_ci
created=0
temporary=$(mktemp -d "${TMPDIR:-/tmp}/portal-f338-XXXXXXXX")
pg() {
    timeout 180s docker compose exec -T --user 82:82 postgres sh -ec \
        'export PGPASSWORD="$(cat /run/secrets/database-admin)"; exec "$@"' sh "$@"
}
sql() {
    local database=$1 query=$2
    pg psql -X -h 127.0.0.1 -U postgres -d "$database" -tA \
        -v ON_ERROR_STOP=1 -c "$query"
}
cleanup() {
    local code=$?
    trap - EXIT
    if (( created == 1 )); then
        if ! pg dropdb -h 127.0.0.1 -U postgres --if-exists --force "$target"; then
            code=1
        fi
    fi
    rm -rf -- "$temporary"
    exit "$code"
}
trap cleanup EXIT

# Refuse populated sources, preexisting targets and ambiguous cluster state.
[[ $(sql portal_identity_dev 'SELECT count(*) FROM public.portal_identities') == 0 ]] || exit 65
[[ $(sql portal_identity_dev "SELECT count(*) FROM pg_database WHERE datname='$target'") == 0 ]] || exit 65

pg pg_dump -h 127.0.0.1 -U postgres --format=custom portal_identity_dev \
    > "$temporary/database.dump"
[[ -s "$temporary/database.dump" ]] || exit 1
pg pg_restore --list < "$temporary/database.dump" > "$temporary/archive.list"
[[ -s "$temporary/archive.list" ]] || exit 1

# A fresh database is created explicitly. No --clean and no writes to source.
pg createdb -h 127.0.0.1 -U postgres --template=template0 "$target"
created=1
pg pg_restore -h 127.0.0.1 -U postgres --dbname="$target" \
    --exit-on-error < "$temporary/database.dump"

marker_query='SELECT marker FROM public.portal_development_installation WHERE singleton AND version=1'
source_marker=$(sql portal_identity_dev "$marker_query")
restored_marker=$(sql "$target" "$marker_query")
[[ ${#source_marker} == 64 && "$source_marker" == "$restored_marker" ]] || exit 1
[[ $(sql "$target" 'SELECT count(*) FROM public.portal_identities') == 0 ]] || exit 1

rls_query="SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
 WHERE n.nspname||'.'||c.relname IN
 ('public.portal_identities','public.portal_auth_sessions',
  'public.portal_memberships','public.portal_membership_grants',
  'public.portal_organizations','public.portal_auth_attempt_buckets',
  'public.portal_browser_envelopes','public.portal_service_resource_claims',
  'portal_browser_private.proof_key')
 AND c.relrowsecurity AND c.relforcerowsecurity"
[[ $(sql "$target" "$rls_query") == 9 ]] || exit 1

# Restore must preserve role ownership and denied direct runtime table reads.
[[ $(sql "$target" "SELECT pg_get_userbyid(p.proowner)
 FROM pg_proc p WHERE p.oid='public.portal_browser_active(text)'::regprocedure") == portal_browser_owner ]] || exit 1
[[ $(sql "$target" "SELECT pg_get_userbyid(p.proowner)
 FROM pg_proc p WHERE p.oid='public.portal_browser_envelope_read(text)'::regprocedure") == portal_browser_envelope_owner ]] || exit 1
[[ $(sql "$target" "SELECT has_table_privilege('portal_browser_runtime',
     'public.portal_identities','SELECT')") == f ]] || exit 1
[[ $(sql "$target" "SELECT has_table_privilege('portal_browser_envelope_runtime',
     'public.portal_browser_envelopes','SELECT')") == f ]] || exit 1
[[ $(sql "$target" "SELECT count(*) FROM portal_browser_private.proof_key") == 1 ]] || exit 1

# Check the source was never changed by the restore operation.
[[ $(sql portal_identity_dev "$marker_query") == "$source_marker" ]] || exit 1
printf 'PASS F338 isolated same-cluster restore: source untouched, RLS and ownership preserved.\n'
