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

# Exercise restored append-only triggers, not merely their names in pg_trigger.
# All attempts target the attested disposable restore DB. Generic failures are
# NOT success: each attempt must hit its specific immutable-history trigger.
# Never print the raw SQL error or captured output.
assert_restored_history_denied() {
    local label="$1" statement="$2" expected="$3" output
    output="$(mktemp "$RUNNER_TEMP/phase15-history-XXXXXXXX.log")"
    if docker exec "$PG_CONTAINER" psql -X -q -v ON_ERROR_STOP=1 -U postgres -d "$target_db" -c "$statement" >"$output" 2>&1; then
        rm -f -- "$output"
        echo "PHASE15_HISTORY_${label}_UNEXPECTED_ALLOW"
        return 1
    fi
    if ! grep -Fq "$expected" "$output"; then
        rm -f -- "$output"
        echo "PHASE15_HISTORY_${label}_WRONG_DENIAL"
        return 1
    fi
    rm -f -- "$output"
}


fingerprint() {
    local database="$1"
    query "$database" "SELECT row_to_json(t)::text FROM public.portal_performance_account_mandates t ORDER BY id" | sha256sum | awk '{print $1}'
    query "$database" "SELECT row_to_json(t)::text FROM public.portal_performance_account_mandate_events t ORDER BY id" | sha256sum | awk '{print $1}'
}
# Cross-provider synthetic identities are separate even when account digits
# match within one company. CI fixtures are not official account authority.
check_channel_separation() {
    local database="$1" pairs events
    pairs="$(query "$database" "SELECT count(*) FROM public.portal_performance_account_mandates g JOIN public.portal_performance_account_mandates m ON m.company_id=g.company_id AND m.account_id=g.account_id AND m.service_principal=g.service_principal WHERE g.account_id='2345678901' AND g.provider='google_ads' AND g.status='revoked' AND g.revision=2 AND g.revoked_at IS NOT NULL AND g.source_kind='synthetic_candidate' AND g.operational_enabled=false AND m.provider='meta_ads' AND m.status='candidate' AND m.revision=2 AND m.revoked_at IS NULL AND m.credential_ref='synthetic-vault-meta-002' AND m.source_kind='synthetic_candidate' AND m.operational_enabled=false")"
    events="$(query "$database" "SELECT count(*) FROM public.portal_performance_account_mandate_events e JOIN public.portal_performance_account_mandates a ON a.id=e.mandate_id AND a.company_id=e.company_id AND a.service_principal=e.service_principal WHERE a.account_id='2345678901' AND ((a.provider='google_ads' AND e.event_kind='revoked' AND e.revision=2) OR (a.provider='meta_ads' AND e.event_kind='revised' AND e.revision=2))")"
    if [[ "$pairs" != 1 || "$events" != 2 ]]; then
        echo "PHASE15_PROVIDER_SELECTIVE_REVOCATION_MISMATCH"
        return 1
    fi
}
mandates="$(query "$source_db" "SELECT count(*) FROM public.portal_performance_account_mandates WHERE status='revoked' AND source_kind='synthetic_candidate' AND operational_enabled=false")"
events="$(query "$source_db" "SELECT count(*) FROM public.portal_performance_account_mandate_events")"
[[ "$mandates" =~ ^[0-9]+$ && "$mandates" -ge 1 ]]
[[ "$events" =~ ^[0-9]+$ && "$events" -ge 3 ]]
[[ "$(query "$source_db" "SELECT count(*) FROM public.portal_performance_account_mandates WHERE operational_enabled=true OR source_kind<>'synthetic_candidate'")" == 0 ]]
check_scope_policies "$source_db"
check_channel_separation "$source_db"
# Only in this composed CI candidate: the terminal revoked→revoked guard
# copied verbatim from the independently tested draft #989 must exist BEFORE
# dump; no SQL grant or real account permission is changed.
if [[ "$(query "$source_db" "SELECT count(*) FROM pg_trigger WHERE tgname='portal_performance_account_revoked_immutable' AND tgenabled='O'")" != 1 ]]; then
    echo "PHASE15_ORIGIN_TERMINAL_REVOKED_GUARD_MISSING"
    exit 1
fi
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
check_channel_separation "$target_db"
echo "PASS PHASE15 RESTORED PROVIDER SELECTIVE REVOCATION"
[[ "$(query "$target_db" "SELECT count(*) FROM pg_class WHERE relname IN ('portal_performance_account_mandates','portal_performance_account_mandate_events') AND relrowsecurity AND relforcerowsecurity")" == 2 ]]
[[ "$(query "$target_db" "SELECT count(*) FROM pg_trigger WHERE tgname IN ('portal_performance_account_mandate_auto_audit','portal_performance_account_mandate_no_delete','portal_performance_account_mandate_no_truncate','portal_performance_account_event_no_truncate') AND tgenabled='O'")" == 4 ]]
[[ "$(query "$target_db" "SELECT count(*) FROM pg_class WHERE relname IN ('portal_performance_account_mandates','portal_performance_account_mandate_events') AND has_table_privilege('portal_performance_runtime',oid,'SELECT,INSERT,UPDATE,DELETE,TRUNCATE')")" == 0 ]]
check_scope_policies "$target_db"
if [[ "$(query "$target_db" "SELECT count(*) FROM pg_trigger WHERE tgname='portal_performance_account_revoked_immutable' AND tgenabled='O'")" != 1 ]]; then
    echo "PHASE15_RESTORE_TERMINAL_REVOKED_GUARD_MISSING"
    exit 1
fi
if [[ "$(policy_fingerprint "$target_db")" != "$source_policy_fingerprint" ]]; then
    echo "PHASE15_RESTORE_POLICY_FINGERPRINT_MISMATCH"
    exit 1
fi
for table in portal_performance_account_mandates portal_performance_account_mandate_events; do
    check_runtime_denied "$target_db" "$table"
done
# The privileged CI SQL identity can inspect synthetic fixtures, but it must
# still be unable to destroy the restored audit history. Verify rows unchanged.
restored_before="$(fingerprint "$target_db")"
assert_restored_history_denied EVENT_UPDATE \
    "UPDATE public.portal_performance_account_mandate_events SET revision=revision+1 WHERE id=(SELECT id FROM public.portal_performance_account_mandate_events ORDER BY id LIMIT 1)" \
    "Account mandate events are append-only"
assert_restored_history_denied EVENT_DELETE \
    "DELETE FROM public.portal_performance_account_mandate_events WHERE id=(SELECT id FROM public.portal_performance_account_mandate_events ORDER BY id LIMIT 1)" \
    "Account mandate events are append-only"
assert_restored_history_denied MANDATE_DELETE \
    "DELETE FROM public.portal_performance_account_mandates WHERE id=(SELECT id FROM public.portal_performance_account_mandates ORDER BY id LIMIT 1)" \
    "Account mandate history cannot be deleted or truncated"
assert_restored_history_denied TRUNCATE_BOTH \
    "TRUNCATE TABLE public.portal_performance_account_mandate_events,public.portal_performance_account_mandates" \
    "Account mandate history cannot be deleted or truncated"
if [[ "$(fingerprint "$target_db")" != "$restored_before" || "$(fingerprint "$source_db")" != "$original" ]]; then
    echo "PHASE15_HISTORY_REJECTED_MUTATION_CHANGED_DATA"
    exit 1
fi
echo "PASS PHASE15 RESTORED AUDIT IMMUTABILITY"
# A revoked fixture restored from the SOURCE must remain irrevocable.
# Catch a specific trigger denial, not an unrelated CHECK/permission failure.
assert_restored_history_denied RESTORED_REVOKED_REACTIVATION \
    "UPDATE public.portal_performance_account_mandates SET status='candidate',revoked_at=NULL,revision=revision+1 WHERE id=(SELECT id FROM public.portal_performance_account_mandates WHERE status='revoked' AND source_kind='synthetic_candidate' ORDER BY id LIMIT 1)" \
    "Revoked mandate cannot be reactivated"
if [[ "$(fingerprint "$target_db")" != "$restored_before" ]]; then
    echo "PHASE15_RESTORED_REVOKED_REPLAY_CHANGED_DATA"
    exit 1
fi
# The older restore check only rejected revoked→candidate. Verify the
# historical draft #989 protection also rejects a *same-status* write:
# mutating an opaque credential reference/expiry after revocation must not
# create a second revoked audit event or change the restored row.
assert_restored_history_denied RESTORED_REVOKED_SAME_STATUS_REWRITE \
    "UPDATE public.portal_performance_account_mandates SET status='revoked',credential_ref='synthetic-vault-rewritten-001',expires_at=expires_at+30,revision=revision+1 WHERE id=(SELECT id FROM public.portal_performance_account_mandates WHERE status='revoked' AND source_kind='synthetic_candidate' ORDER BY id LIMIT 1)" \
    "Revoked account mandate is immutable"
if [[ "$(fingerprint "$target_db")" != "$restored_before" || "$(fingerprint "$source_db")" != "$original" ]]; then
    echo "PHASE15_TERMINAL_REVOKED_REWRITE_CHANGED_DATA"
    exit 1
fi
echo "PASS PHASE15 RESTORED TERMINAL REVOKED SNAPSHOT"
echo "PASS PHASE15 RESTORED REVOKED REMAINS REVOKED"
docker exec "$PG_CONTAINER" psql -X -q -v ON_ERROR_STOP=1 -U postgres -d "$target_db" -c "
INSERT INTO public.portal_performance_account_mandates
  (id,company_id,provider,account_id,service_principal,consent_reference,credential_ref,status,revision,expires_at)
SELECT gen_random_uuid(),company_id,'google_ads','5555555555','imagine_portal_s2s',
       'synthetic-restore-probe-001','synthetic-vault-restore-001','candidate',1,extract(epoch from now())::bigint+600
FROM public.portal_performance_services LIMIT 1" >/dev/null
[[ "$(query "$target_db" "SELECT count(*) FROM public.portal_performance_account_mandate_events e JOIN public.portal_performance_account_mandates m ON m.id=e.mandate_id WHERE m.account_id='5555555555' AND e.event_kind='created'")" == 1 ]]
# The newly inserted candidate must also be revocable AFTER pg_restore.
# Use independent psql/docker processes for insert, transition and readback.
if [[ "$(query "$target_db" "SELECT count(*) FROM public.portal_performance_account_mandates WHERE account_id='5555555555' AND status='candidate' AND source_kind='synthetic_candidate' AND operational_enabled=false")" != 1 ]]; then
    echo "PHASE15_POST_RESTORE_CANDIDATE_MISSING"
    exit 1
fi
docker exec "$PG_CONTAINER" psql -X -q -v ON_ERROR_STOP=1 -U postgres -d "$target_db" -c "
UPDATE public.portal_performance_account_mandates
SET status='revoked',revoked_at=extract(epoch from now())::bigint,revision=revision+1
WHERE account_id='5555555555' AND status='candidate' AND source_kind='synthetic_candidate' AND operational_enabled=false" >/dev/null
if [[ "$(query "$target_db" "SELECT count(*) FROM public.portal_performance_account_mandates WHERE account_id='5555555555' AND status='revoked' AND revoked_at IS NOT NULL AND revision=2 AND source_kind='synthetic_candidate' AND operational_enabled=false")" != 1 ||
      "$(query "$target_db" "SELECT count(*) FROM public.portal_performance_account_mandate_events e JOIN public.portal_performance_account_mandates m ON m.id=e.mandate_id AND m.company_id=e.company_id AND m.service_principal=e.service_principal WHERE m.account_id='5555555555' AND e.event_kind='revoked' AND e.revision=2")" != 1 ||
      "$(query "$target_db" "SELECT count(*) FROM public.portal_performance_account_mandate_events e JOIN public.portal_performance_account_mandates m ON m.id=e.mandate_id WHERE m.account_id='5555555555'")" != 2 ]]; then
    echo "PHASE15_POST_RESTORE_REVOCATION_AUDIT_MISMATCH"
    exit 1
fi
revoked_after="$(fingerprint "$target_db")"
assert_restored_history_denied NEWLY_REVOKED_REACTIVATION \
    "UPDATE public.portal_performance_account_mandates SET status='candidate',revoked_at=NULL,revision=revision+1 WHERE account_id='5555555555' AND status='revoked'" \
    "Revoked mandate cannot be reactivated"
if [[ "$(fingerprint "$target_db")" != "$revoked_after" || "$(fingerprint "$source_db")" != "$original" ]]; then
    echo "PHASE15_POST_RESTORE_REACTIVATION_CHANGED_DATA"
    exit 1
fi
# Repeat after a fresh candidate→revoked transition in a different SQL
# connection, preventing an allowed revoked→revoked consent rewrite.
assert_restored_history_denied POST_RESTORE_REVOKED_CONSENT_REWRITE \
    "UPDATE public.portal_performance_account_mandates SET status='revoked',consent_reference='synthetic-consent-rewritten-001',revision=revision+1 WHERE account_id='5555555555' AND status='revoked'" \
    "Revoked account mandate is immutable"
if [[ "$(fingerprint "$target_db")" != "$revoked_after" || "$(fingerprint "$source_db")" != "$original" ]]; then
    echo "PHASE15_POST_RESTORE_TERMINAL_REWRITE_CHANGED_DATA"
    exit 1
fi
echo "PASS PHASE15 POST-RESTORE TERMINAL REVOKED SNAPSHOT"
echo "PASS PHASE15 POST-RESTORE REVOCATION IS DURABLE"
[[ "$(fingerprint "$source_db")" == "$original" ]]
echo "PHASE15_POPULATED_MANDATE_RESTORE_PASS: nonempty revoked candidate and events restored, FORCE RLS, scope-policy metadata and executed runtime SELECT denials preserved, source untouched"
