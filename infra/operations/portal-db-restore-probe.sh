#!/usr/bin/env bash
# Empty development foundation only: never restores over the source database.
set -Eeuo pipefail
umask 077
[[ $EUID == 0 && $# == 1 ]] || { printf 'Root and one private backup directory required.\n' >&2; exit 64; }
backup=$(realpath -e -- "$1")
[[ $backup == /srv/imagine/backups/portal-db/* && -f $backup/COMPLETE ]] || exit 64
(cd "$backup"; sha256sum --check --status SHA256SUMS)
exec 9>/run/lock/imagine-portal-restore-check.lock
flock -n 9 || exit 75
cd /srv/imagine/portal-development/infra/development/portal-db
pg() {
    timeout 180s docker compose exec -T --user 82:82 postgres sh -ec \
        'export PGPASSWORD="$(cat /run/secrets/database-admin)"; exec "$@"' sh "$@"
}
sql() {
    local database=$1 query=$2
    pg psql -X -h 127.0.0.1 -U postgres -d "$database" -tA -v ON_ERROR_STOP=1 -c "$query"
}
[[ $(sql portal_identity_dev 'SELECT count(*) FROM public.portal_identities') == 0 ]] || {
    printf 'Restore probe requires a development foundation with no identities.\n' >&2; exit 65;
}
created=0
cleanup() {
    local result=$?
    trap - EXIT
    if [[ $created == 1 ]]; then
        pg dropdb -h 127.0.0.1 -U postgres portal_identity_restore_probe || result=1
    fi
    exit "$result"
}
trap cleanup EXIT
# Existing target is never reused or deleted: createdb must succeed first.
pg createdb -h 127.0.0.1 -U postgres --template=template0 portal_identity_restore_probe
created=1
pg pg_restore -h 127.0.0.1 -U postgres --dbname=portal_identity_restore_probe \
    --clean --if-exists --exit-on-error < "$backup/database.dump"
query="SELECT encode(public.digest(marker,'sha256'),'hex') FROM public.portal_development_installation WHERE singleton AND version=1"
source_marker=$(sql portal_identity_dev "$query")
restored_marker=$(sql portal_identity_restore_probe "$query")
[[ -n $source_marker && $source_marker == "$restored_marker" ]]
[[ $(sql portal_identity_restore_probe 'SELECT count(*) FROM public.portal_identities') == 0 ]]
[[ $(sql portal_identity_restore_probe 'SELECT count(*) FROM portal_browser_private.proof_key') == 1 ]]
[[ $(sql portal_identity_restore_probe "SELECT count(*) FROM pg_class WHERE relname IN ('portal_identities','portal_auth_sessions','portal_memberships','portal_membership_grants','portal_organizations','portal_auth_attempt_buckets','portal_browser_envelopes','portal_service_resource_claims') AND relrowsecurity AND relforcerowsecurity") == 8 ]]
[[ $(sql portal_identity_restore_probe "SELECT count(*) FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace WHERE n.nspname='public' AND p.proname IN ('portal_browser_begin','portal_browser_complete') AND p.prosecdef AND pg_get_userbyid(p.proowner)='portal_browser_owner'") == 2 ]]
[[ $(sql portal_identity_restore_probe "SELECT has_table_privilege('portal_browser_runtime','public.portal_identities','SELECT') OR has_table_privilege('portal_browser_envelope_runtime','public.portal_browser_envelopes','SELECT')") == f ]]
printf 'PASS isolated empty-foundation restore: marker, RLS and function ownership preserved.\n'
