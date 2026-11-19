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
cleanup() {
    rm -f -- "$archive"
    docker exec "$PG_CONTAINER" dropdb -U postgres --if-exists "$target_db" >/dev/null 2>&1 || true
}
trap cleanup EXIT
query() {
    docker exec "$PG_CONTAINER" psql -X -qAt -v ON_ERROR_STOP=1 -U postgres -d "$1" -c "$2"
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
original="$(fingerprint "$source_db")"

docker exec "$PG_CONTAINER" pg_dump -U postgres -d "$source_db" -Fc > "$archive"
[[ -s "$archive" ]]
digest="$(sha256sum "$archive" | awk '{print $1}')"
docker exec "$PG_CONTAINER" createdb -U postgres "$target_db"
docker exec -i "$PG_CONTAINER" pg_restore -U postgres -d "$target_db" --exit-on-error --single-transaction < "$archive"
[[ "$(sha256sum "$archive" | awk '{print $1}')" == "$digest" ]]
[[ "$(fingerprint "$target_db")" == "$original" ]]
[[ "$(query "$target_db" "SELECT count(*) FROM pg_class WHERE relname IN ('portal_performance_account_mandates','portal_performance_account_mandate_events') AND relrowsecurity AND relforcerowsecurity")" == 2 ]]
[[ "$(query "$target_db" "SELECT count(*) FROM pg_constraint WHERE conname IN ('account_mandate_consent_reference_shape','account_mandate_credential_reference_shape','account_mandate_event_revision_unique')")" == 3 ]]
[[ "$(query "$target_db" "SELECT count(*) FROM pg_trigger WHERE tgname IN ('portal_performance_account_mandate_auto_audit','portal_performance_account_mandate_no_delete','portal_performance_account_mandate_no_truncate','portal_performance_account_event_no_truncate') AND tgenabled='O'")" == 4 ]]
[[ "$(query "$target_db" "SELECT count(*) FROM pg_trigger WHERE tgname='portal_performance_account_event_current_revision_guard' AND tgenabled='O'")" == 1 ]]
[[ "$(query "$target_db" "SELECT count(*) FROM pg_trigger WHERE tgname='portal_performance_account_revoked_immutable' AND tgenabled='O'")" == 1 ]]
[[ "$(query "$target_db" "SELECT count(*) FROM pg_class WHERE relname IN ('portal_performance_account_mandates','portal_performance_account_mandate_events') AND has_table_privilege('portal_performance_runtime',oid,'SELECT,INSERT,UPDATE,DELETE,TRUNCATE')")" == 0 ]]
docker exec "$PG_CONTAINER" psql -X -q -v ON_ERROR_STOP=1 -U postgres -d "$target_db" -c "
INSERT INTO public.portal_performance_account_mandates
  (id,company_id,provider,account_id,service_principal,consent_reference,credential_ref,status,revision,expires_at)
SELECT gen_random_uuid(),company_id,'google_ads','5555555555','imagine_portal_s2s',
       'synthetic-restore-probe-001','synthetic-vault-restore-001','candidate',1,extract(epoch from now())::bigint+600
FROM public.portal_performance_services LIMIT 1" >/dev/null
[[ "$(query "$target_db" "SELECT count(*) FROM public.portal_performance_account_mandate_events e JOIN public.portal_performance_account_mandates m ON m.id=e.mandate_id WHERE m.account_id='5555555555' AND e.event_kind='created'")" == 1 ]]
# Verify that the restored guard blocks a forged future event despite a
# valid parent, and leaves the automatically generated current event intact.
if docker exec "$PG_CONTAINER" psql -X -q -v ON_ERROR_STOP=1 -U postgres -d "$target_db" -c "
INSERT INTO public.portal_performance_account_mandate_events
  (id,mandate_id,company_id,service_principal,revision,event_kind,correlation_id)
SELECT gen_random_uuid(),m.id,m.company_id,m.service_principal,m.revision+1,'revised',gen_random_uuid()
FROM public.portal_performance_account_mandates AS m
WHERE m.account_id='5555555555'
" >/dev/null 2>&1; then
    echo "FAIL: restored database accepted future-revision audit event" >&2
    exit 1
fi
[[ "$(query "$target_db" "SELECT count(*) FROM public.portal_performance_account_mandate_events e JOIN public.portal_performance_account_mandates m ON m.id=e.mandate_id WHERE m.account_id='5555555555'")" == 1 ]]

# The restored schema must refuse any rewrite of an already revoked mandate.
# This is a real negative mutation of restored synthetic rows, not a metadata-only check.
if query "$target_db" "UPDATE public.portal_performance_account_mandates SET revision=revision+1, credential_ref='synthetic-vault-forged-999' WHERE status='revoked'" >/dev/null 2>&1; then
    echo "FAIL: restored database allowed a post-revocation reference rewrite" >&2
    exit 1
fi
[[ "$(query "$target_db" "SELECT count(*) FROM public.portal_performance_account_mandates WHERE status='revoked' AND revision>3")" == 0 ]]
[[ "$(fingerprint "$source_db")" == "$original" ]]
echo "PHASE15_POPULATED_MANDATE_RESTORE_PASS: nonempty revoked candidate and events restored, FORCE RLS and runtime-deny preserved, source untouched"
