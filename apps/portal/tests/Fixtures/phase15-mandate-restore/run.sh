#!/usr/bin/env bash
set -euo pipefail
# Synthetic disposable PostgreSQL only. Not an external/VPS backup or restore.
[[ "$GITHUB_ACTIONS" == true && "$CI" == true && "$ADMIN_PHASE4_PG_CI" == 1 ]]
[[ "$DB_DATABASE" == performance_phase4_ci && "$APP_ENV" == testing ]]
[[ "$PG_CONTAINER" =~ ^[0-9a-f]{12,64}$ && -z "$DOCKER_HOST" && -z "$DOCKER_CONTEXT" ]]
[[ "$(docker inspect -f '{{.Config.Image}}' "$PG_CONTAINER")" == postgres:18 ]]
[[ "$(docker inspect -f '{{.State.Running}}' "$PG_CONTAINER")" == true ]]

source_db=performance_phase4_ci
target_db=performance_mandate_restore_ci
umask 077
archive="$(mktemp "$RUNNER_TEMP/performance-mandate-restore-XXXXXXXX.dump")"
target_created=0
cleanup() {
    rm -f -- "$archive"
    # Never delete a database that this run did not create.
    if [[ "$target_created" == 1 ]]; then
        docker exec "$PG_CONTAINER" dropdb -U postgres --if-exists "$target_db" >/dev/null 2>&1 || true
    fi
}
trap cleanup EXIT
query() {
    docker exec "$PG_CONTAINER" psql -X -qAt -v ON_ERROR_STOP=1 -U postgres -d "$1" -c "$2"
}
# Abort BEFORE pg_dump/createdb/pg_restore if another run already owns this
# destination. The EXIT trap must not remove the pre-existing database.
if [[ "$(query "$source_db" "SELECT count(*) FROM pg_database WHERE datname='$target_db'")" != 0 ]]; then
    echo "PHASE15_RESTORE_DESTINATION_PREEXISTS_NO_DELETE"
    exit 1
fi
# Read-only policy metadata guard: presence, identity and tenant/principal
# predicates on BOTH USING and WITH CHECK. This does not prove positive tenant
# access; the actual runtime role deliberately has no SELECT grant.
check_scope_policies() {
    local database="$1"
    local all_count scoped_count
    all_count="$(query "$database" "SELECT count(*) FROM pg_policies WHERE schemaname='public' AND tablename IN ('portal_performance_account_mandates','portal_performance_account_mandate_events')")"
    scoped_count="$(query "$database" "SELECT count(*) FROM pg_policies WHERE schemaname='public' AND ((tablename='portal_performance_account_mandates' AND policyname='portal_performance_account_scope') OR (tablename='portal_performance_account_mandate_events' AND policyname='portal_performance_account_event_scope')) AND permissive='PERMISSIVE' AND cmd='ALL' AND roles=ARRAY['public']::name[] AND strpos(qual,'app.performance_company')>0 AND strpos(qual,'app.performance_principal')>0 AND strpos(with_check,'app.performance_company')>0 AND strpos(with_check,'app.performance_principal')>0")"
    if [[ "$all_count" != 2 || "$scoped_count" != 2 ]]; then
        echo "PHASE15_RESTORE_SCOPE_POLICY_MISMATCH"
        return 1
    fi
}
# Compare the complete, deterministically ordered policy definitions
# on both sides of pg_restore, not just whether expected predicate names occur.
# A destination-only policy broadening with an OR clause must not pass.
policy_fingerprint() {
    local database="$1"
    query "$database" "SELECT row_to_json(p)::text FROM (SELECT schemaname,tablename,policyname,permissive,roles,cmd,qual,with_check FROM pg_policies WHERE schemaname='public' AND tablename IN ('portal_performance_account_mandates','portal_performance_account_mandate_events') ORDER BY tablename,policyname) p" | sha256sum | awk '{print $1}'
}
# Execute an actual negative SELECT as the restricted SQL role, even if a
# caller forges a matching company/principal session claim. No role is created,
# no GRANT is added, and the database is the already-attested disposable CI DB.
check_runtime_denied() {
    local database="$1" table="$2" output
    output="$(mktemp "$RUNNER_TEMP/phase15-runtime-deny-XXXXXXXX.log")"
    if docker exec "$PG_CONTAINER" psql -X -q -v ON_ERROR_STOP=1 -U postgres -d "$database" -c "SET ROLE portal_performance_runtime; SET app.performance_company='00000000-0000-4000-8000-000000000001'; SET app.performance_principal='imagine_portal_s2s'; SELECT count(*) FROM public.$table" >"$output" 2>&1; then
        rm -f -- "$output"
        echo "PHASE15_RESTORE_RUNTIME_READ_UNEXPECTEDLY_ALLOWED"
        return 1
    fi
    if ! grep -Fq "permission denied for table $table" "$output"; then
        rm -f -- "$output"
        echo "PHASE15_RESTORE_RUNTIME_DENIAL_NOT_PROVEN"
        return 1
    fi
    rm -f -- "$output"
}

fingerprint() {
    local database="$1"
    query "$database" "SELECT row_to_json(t)::text FROM public.portal_performance_account_mandates t ORDER BY id" | sha256sum | awk '{print $1}'
    query "$database" "SELECT row_to_json(t)::text FROM public.portal_performance_account_mandate_events t ORDER BY id" | sha256sum | awk '{print $1}'
}
mandates="$(query "$source_db" "SELECT count(*) FROM public.portal_performance_account_mandates WHERE status='revoked' AND source_kind='synthetic_candidate' AND operational_enabled=false")"
events="$(query "$source_db" "SELECT count(*) FROM public.portal_performance_account_mandate_events")"
[[ "$mandates" =~ ^[0-9]+$ && "$mandates" -ge 1 ]]
[[ "$events" =~ ^[0-9]+$ && "$events" -ge 3 ]]
[[ "$(query "$source_db" "SELECT count(*) FROM public.portal_performance_account_mandates WHERE operational_enabled=true OR source_kind<>'synthetic_candidate'")" == 0 ]]
check_scope_policies "$source_db"
source_policy_fingerprint="$(policy_fingerprint "$source_db")"
for table in portal_performance_account_mandates portal_performance_account_mandate_events; do
    check_runtime_denied "$source_db" "$table"
done
original="$(fingerprint "$source_db")"

docker exec "$PG_CONTAINER" pg_dump -U postgres -d "$source_db" -Fc > "$archive"
[[ -s "$archive" ]]
digest="$(sha256sum "$archive" | awk '{print $1}')"
docker exec "$PG_CONTAINER" createdb -U postgres "$target_db"
target_created=1
docker exec -i "$PG_CONTAINER" pg_restore -U postgres -d "$target_db" --exit-on-error --single-transaction < "$archive"
[[ "$(sha256sum "$archive" | awk '{print $1}')" == "$digest" ]]
[[ "$(fingerprint "$target_db")" == "$original" ]]
[[ "$(query "$target_db" "SELECT count(*) FROM pg_class WHERE relname IN ('portal_performance_account_mandates','portal_performance_account_mandate_events') AND relrowsecurity AND relforcerowsecurity")" == 2 ]]
[[ "$(query "$target_db" "SELECT count(*) FROM pg_trigger WHERE tgname IN ('portal_performance_account_mandate_auto_audit','portal_performance_account_mandate_no_delete','portal_performance_account_mandate_no_truncate','portal_performance_account_event_no_truncate') AND tgenabled='O'")" == 4 ]]
[[ "$(query "$target_db" "SELECT count(*) FROM pg_class WHERE relname IN ('portal_performance_account_mandates','portal_performance_account_mandate_events') AND has_table_privilege('portal_performance_runtime',oid,'SELECT,INSERT,UPDATE,DELETE,TRUNCATE')")" == 0 ]]
check_scope_policies "$target_db"
if [[ "$(policy_fingerprint "$target_db")" != "$source_policy_fingerprint" ]]; then
    echo "PHASE15_RESTORE_POLICY_FINGERPRINT_MISMATCH"
    exit 1
fi
for table in portal_performance_account_mandates portal_performance_account_mandate_events; do
    check_runtime_denied "$target_db" "$table"
done
docker exec "$PG_CONTAINER" psql -X -q -v ON_ERROR_STOP=1 -U postgres -d "$target_db" -c "
INSERT INTO public.portal_performance_account_mandates
  (id,company_id,provider,account_id,service_principal,consent_reference,credential_ref,status,revision,expires_at)
SELECT gen_random_uuid(),company_id,'google_ads','5555555555','imagine_portal_s2s',
       'synthetic-restore-probe-001','synthetic-vault-restore-001','candidate',1,extract(epoch from now())::bigint+600
FROM public.portal_performance_services LIMIT 1" >/dev/null
[[ "$(query "$target_db" "SELECT count(*) FROM public.portal_performance_account_mandate_events e JOIN public.portal_performance_account_mandates m ON m.id=e.mandate_id WHERE m.account_id='5555555555' AND e.event_kind='created'")" == 1 ]]
[[ "$(fingerprint "$source_db")" == "$original" ]]
echo "PHASE15_POPULATED_MANDATE_RESTORE_PASS: nonempty revoked candidate and events restored, FORCE RLS, scope-policy metadata and executed runtime SELECT denials preserved, source untouched"
