#!/usr/bin/env bash
# CI-only cold restoration of the SAME synthetic F423 Google-only journey.
# Never an external backup, RTO proof, official consent or operational GO.
set -euo pipefail
[[ "${GITHUB_ACTIONS:-}" == true ]] || { echo 'F423 RESTORE: not CI' >&2; exit 78; }
[[ -f compose.ci.yaml && -f compose.yaml && -f f423-google-restore-preflight.php ]] || exit 78

src=portal_purchase_rehearsal
dst=portal_f423_restore_disposable_ci
compose() { docker compose -f compose.yaml -f compose.ci.yaml "$@"; }
sql() {
    compose exec -T database psql -X -q -t -A -v ON_ERROR_STOP=1 -U postgres -d "$1" -c "$2"
}
origin=$(sql "$src" "SELECT current_database()||'|'||current_user||'|'||current_setting('server_version_num')")
[[ "$origin" =~ ^portal_purchase_rehearsal\|postgres\|18[0-9]{4}$ ]] || {
    echo 'F423 RESTORE: source is not attested disposable PG18' >&2; exit 1;
}
[[ "$(sql postgres "SELECT count(*) FROM pg_database WHERE datname='$dst'")" == 0 ]] || {
    echo 'F423 RESTORE: destination preexists, refusing overwrite' >&2; exit 1;
}

tables=(
 f423_briefing_reviews
 f423_performance_decisions
 f423_google_diagnostic_plans
 f423_google_plan_approvals
 f423_google_internal_drafts
 f423_google_monitoring_samples
 f423_google_offline_improvements
 f423_google_improvement_approvals
 f423_google_offline_applied_results
)
manifest() {
    local db="$1" t flags count digest
    for t in "${tables[@]}"; do
        flags=$(sql "$db" "SELECT relrowsecurity::text||'|'||relforcerowsecurity::text FROM pg_class WHERE oid='purchase_rehearsal.$t'::regclass")
        [[ "$flags" == 'true|true' ]] || { echo "F423 RESTORE: FORCE RLS lost: $t" >&2; return 1; }
        count=$(sql "$db" "SELECT count(*) FROM purchase_rehearsal.$t")
        [[ "$count" =~ ^[1-9][0-9]*$ ]] || { echo "F423 RESTORE: missing tested ledger: $t" >&2; return 1; }
        digest=$(compose exec -T database psql -X -q -t -A -v ON_ERROR_STOP=1 -U postgres -d "$db" -c \
          "COPY (SELECT row_to_json(x)::text AS payload FROM purchase_rehearsal.$t x ORDER BY payload) TO STDOUT" \
          | sha256sum | cut -d' ' -f1)
        printf '%s|%s|%s|%s\n' "$t" "$flags" "$count" "$digest"
    done
}
[[ "$(sql "$src" "SELECT count(*) FROM purchase_rehearsal.f423_google_monitoring_samples")" == 2 ]] || exit 1
[[ "$(sql "$src" "SELECT count(*) FROM purchase_rehearsal.f423_google_offline_applied_results")" == 1 ]] || exit 1
[[ "$(sql "$src" "SELECT count(*) FROM purchase_rehearsal.f423_google_offline_applied_results WHERE provider<>'google_ads'")" == 0 ]] || exit 1
[[ "$(sql "$src" "SELECT has_table_privilege('purchase_rehearsal_a', 'purchase_rehearsal.f423_google_offline_applied_results', 'UPDATE')")" == f ]] || exit 1

# Avoid storing SQL rows outside the disposable CI stack. Host receives only a
# temporary custom-format dump and row digests; all are deleted on exit.
work=$(mktemp -d)
trap 'rm -rf -- "$work"' EXIT
manifest "$src" > "$work/source.manifest"
# One and only one buyer from the attested SOURCE F423 fixture, not from an
# admin-scope query against the restored DB. Never print this synthetic ID.
fixture_buyer=$(sql "$src" "SELECT DISTINCT customer_id FROM purchase_rehearsal.f423_google_offline_applied_results ORDER BY customer_id")
[[ "$fixture_buyer" =~ ^demo-[a-f0-9]{32}$ ]] || {
    echo "F423 RESTORE: ambiguous source fixture buyer" >&2; exit 1;
}
# Before any DDL, confirm the EFFECTIVE Laravel bootstrap + TCP role;
# the absence of the target database is verified both there and above.
compose exec -T application php f423-google-restore-preflight.php

compose exec -T database pg_dump -U postgres -Fc --no-owner -d "$src" > "$work/source.dump"
[[ "$(wc -c < "$work/source.dump")" -gt 10000 ]] || exit 1
dump_hash=$(sha256sum "$work/source.dump" | cut -d' ' -f1)
[[ "$dump_hash" =~ ^[0-9a-f]{64}$ ]] || exit 1

# Create only a fresh, explicitly named SECOND database in the short-lived
# CI PostgreSQL container; never touch the original, VPS or main/production.
compose exec -T database createdb -U postgres -T template0 "$dst"
# Adversarial CI check: once the destination exists, an otherwise valid
# Laravel/source SQL preflight MUST refuse a second restore invocation.
if compose exec -T application php f423-google-restore-preflight.php >/dev/null 2>&1; then
    echo "F423 RESTORE: preflight incorrectly accepted an existing target" >&2
    exit 1
fi
[[ "$(sql "$dst" "SELECT current_database()||'|'||current_setting('server_version_num')")" =~ ^portal_f423_restore_disposable_ci\|18[0-9]{4}$ ]] || exit 1
compose exec -T database pg_restore -U postgres --exit-on-error --no-owner -d "$dst" < "$work/source.dump"
[[ "$(sha256sum "$work/source.dump" | cut -d' ' -f1)" == "$dump_hash" ]] || exit 1

manifest "$dst" > "$work/restored.manifest"
cmp "$work/source.manifest" "$work/restored.manifest"
# Prove row-level isolation by the REAL restricted SQL login in the newly
# restored database, not merely by catalog flags or superuser SELECTs.
# The verifier also rejects absent/forged/buyer-mismatched signed GUC scopes.
printf '%s\n' "$fixture_buyer" | compose exec -T application php f423-google-restored-rls-readback.php
# Source was never rewritten or modified by the new restore candidate.
manifest "$src" > "$work/after.manifest"
cmp "$work/source.manifest" "$work/after.manifest"
[[ "$(sql "$dst" "SELECT count(*) FROM purchase_rehearsal.f423_google_monitoring_samples")" == 2 ]] || exit 1
[[ "$(sql "$dst" "SELECT count(*) FROM purchase_rehearsal.f423_google_offline_applied_results")" == 1 ]] || exit 1
[[ "$(sql "$dst" "SELECT has_table_privilege('purchase_rehearsal_a', 'purchase_rehearsal.f423_google_offline_applied_results', 'UPDATE')")" == f ]] || exit 1
# Genuine negative SQL attempt in the restored database only: even an
# UPDATE matching zero rows must be denied by SQL privileges. No mutation occurs.
if sql "$dst" "SET ROLE purchase_rehearsal_a; UPDATE purchase_rehearsal.f423_google_offline_applied_results SET provider=provider WHERE false" >/dev/null 2>&1; then
    echo "F423 RESTORE: restricted role unexpectedly obtained UPDATE" >&2
    exit 1
fi
[[ "$(sql "$dst" "SELECT count(*) FROM purchase_rehearsal.f423_google_offline_applied_results WHERE provider<>'google_ads'")" == 0 ]] || exit 1

manifest_hash=$(sha256sum "$work/source.manifest" | cut -d' ' -f1)
echo "PASS F423 RESTORE: 9 synthetic ledgers identical source/restored/source-after, FORCE RLS, 2 offline samples, 1 unpublished offline result, restricted SQL UPDATE, SHA256 manifest=$manifest_hash, custom dump SHA256=$dump_hash, NO-GO"
