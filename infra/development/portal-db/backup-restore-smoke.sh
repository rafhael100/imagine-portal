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
# Missing identities do not imply an empty database: attempt buckets, envelopes,
# service claims or sessions can exist independently. This is a ZERO-DATA drill.
empty_source_query='SELECT
 (SELECT count(*) FROM public.portal_identities) +
 (SELECT count(*) FROM public.portal_organizations) +
 (SELECT count(*) FROM public.portal_memberships) +
 (SELECT count(*) FROM public.portal_membership_grants) +
 (SELECT count(*) FROM public.portal_auth_sessions) +
 (SELECT count(*) FROM public.portal_auth_attempt_buckets) +
 (SELECT count(*) FROM public.portal_browser_envelopes) +
 (SELECT count(*) FROM public.portal_service_resource_claims)'
[[ $(sql portal_identity_dev "$empty_source_query") == 0 ]] || exit 65
[[ $(sql portal_identity_dev "SELECT count(*) FROM pg_database WHERE datname='$target'") == 0 ]] || exit 65
marker_query='SELECT marker FROM public.portal_development_installation WHERE singleton AND version=1'
source_marker=$(sql portal_identity_dev "$marker_query")
[[ ${#source_marker} == 64 && "$source_marker" =~ ^[[:xdigit:]]{64}$ ]] || exit 65
# The disposable foundation should have precisely its ten reviewed migrations.
migration_query="SELECT md5(string_agg(migration,',' ORDER BY migration))
 FROM public.migrations"
[[ $(sql portal_identity_dev 'SELECT count(*) FROM public.migrations') == 10 ]] || exit 65
# Count-only gates could accept ten unexpected migration names. Pin the reviewed set.
allowlist_query="SELECT count(*) FROM public.migrations WHERE migration NOT IN (
  '2026_11_09_000002_create_portal_identity_foundation',
  '2026_11_09_000003_add_mfa_replay_counter_to_portal_identities',
  '2026_11_10_000004_add_org_revision_to_portal_sessions',
  '2026_11_10_000005_create_portal_auth_throttle_buckets',
  '2026_11_10_000008_add_session_purpose_to_portal_auth_sessions',
  '2026_11_11_000012_fence_portal_membership_revision',
  '2026_11_11_000013_fence_portal_organization_revision',
  '2026_11_11_000014_guard_portal_session_issuance',
  '2026_11_12_000016_fence_portal_identity_revision',
  '2026_11_12_000017_create_portal_service_resource_claims'
)"
[[ $(sql portal_identity_dev "$allowlist_query") == 0 ]] || exit 65
source_migrations=$(sql portal_identity_dev "$migration_query")
[[ "$source_migrations" =~ ^[[:xdigit:]]{32}$ ]] || exit 65

pg pg_dump -h 127.0.0.1 -U postgres --format=custom portal_identity_dev \
    > "$temporary/database.dump"
[[ -s "$temporary/database.dump" ]] || exit 1
pg pg_restore --list < "$temporary/database.dump" > "$temporary/archive.list"
[[ -s "$temporary/archive.list" ]] || exit 1
# A concurrent source change invalidates the archive, before a target is created.
[[ $(sql portal_identity_dev "$marker_query") == "$source_marker" ]] || exit 1
[[ $(sql portal_identity_dev "$empty_source_query") == 0 ]] || exit 65

# A fresh database is created explicitly. No --clean and no writes to source.
pg createdb -h 127.0.0.1 -U postgres --template=template0 "$target"
created=1
# createdb/template0 grants CONNECT to PUBLIC by default. Close the restored
# target to all unspecified roles before importing any sensitive schema objects.
sql postgres "REVOKE CONNECT ON DATABASE $target FROM PUBLIC" >/dev/null
[[ $(sql postgres "SELECT has_database_privilege('portal_browser_runtime',
 '$target','CONNECT')") == f ]] || exit 1
[[ $(sql postgres "SELECT has_database_privilege('portal_browser_envelope_runtime',
 '$target','CONNECT')") == f ]] || exit 1
pg pg_restore -h 127.0.0.1 -U postgres --dbname="$target" \
    --exit-on-error < "$temporary/database.dump"
# Re-check after import: dump ACL commands must not reopen the target.
[[ $(sql postgres "SELECT has_database_privilege('portal_browser_runtime',
 '$target','CONNECT')") == f ]] || exit 1
[[ $(sql postgres "SELECT has_database_privilege('portal_browser_envelope_runtime',
 '$target','CONNECT')") == f ]] || exit 1
# Reject CONNECT assigned to any unknown third-party role, not only PUBLIC.
[[ $(sql postgres "SELECT NOT EXISTS (
 SELECT 1 FROM pg_database d,
 LATERAL aclexplode(coalesce(d.datacl,acldefault('d',d.datdba))) acl
 WHERE d.datname='$target' AND acl.privilege_type='CONNECT'
 AND acl.grantee <> (SELECT oid FROM pg_roles WHERE rolname='postgres'))") == t ]] || exit 1

restored_marker=$(sql "$target" "$marker_query")
[[ ${#source_marker} == 64 && "$source_marker" == "$restored_marker" ]] || exit 1
[[ $(sql "$target" 'SELECT count(*) FROM public.migrations') == 10 ]] || exit 1
[[ $(sql "$target" "$allowlist_query") == 0 ]] || exit 1
[[ $(sql "$target" "$migration_query") == "$source_migrations" ]] || exit 1
# F349: check all thirteen restored SECURITY DEFINER function contracts,
# including the exact EXECUTE matrix and reviewed migration-name set.
pg psql -X -h 127.0.0.1 -U postgres -d "$target" \
    -v ON_ERROR_STOP=1 < ./restore-contracts.sql >/dev/null
[[ $(sql "$target" "$empty_source_query") == 0 ]] || exit 1

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

# Both runtime identities must have zero direct table privileges after restore.
# Include schema-qualified names to avoid search_path ambiguity.
table_privileges_query="SELECT count(*)
 FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
 CROSS JOIN (VALUES ('portal_browser_runtime'),('portal_browser_envelope_runtime')) AS roles(role)
 WHERE n.nspname||'.'||c.relname IN
 ('public.portal_identities','public.portal_auth_sessions',
  'public.portal_memberships','public.portal_membership_grants',
  'public.portal_organizations','public.portal_auth_attempt_buckets',
  'public.portal_browser_envelopes','public.portal_service_resource_claims',
  'portal_browser_private.proof_key',
  'public.portal_development_installation')
 AND has_table_privilege(roles.role,c.oid,
     'SELECT,INSERT,UPDATE,DELETE,TRUNCATE,REFERENCES,TRIGGER')"
[[ $(sql "$target" "$table_privileges_query") == 0 ]] || exit 1

# Check the source was never changed by the restore operation.
[[ $(sql portal_identity_dev "$marker_query") == "$source_marker" ]] || exit 1
printf 'PASS F338 isolated same-cluster restore: source untouched, RLS and ownership preserved.\n'
