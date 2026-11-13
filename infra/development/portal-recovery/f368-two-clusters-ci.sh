#!/usr/bin/env bash
# F368: disposable independent PostgreSQL 18 witness. NEVER run on the VPS.
set -Eeuo pipefail
umask 077
[[ "${GITHUB_ACTIONS:-}" == true && "${RUNNER_ENVIRONMENT:-}" == github-hosted &&
   "${PORTAL_RESTORE_WITNESS_CI:-}" == DISPOSABLE_ONLY &&
   "${PORTAL_DB_DISPOSABLE_CI:-}" == 1 &&
   "${COMPOSE_PROJECT_NAME:-}" == imagine-portal-f368-ci ]] || exit 64
cd "$(dirname "$0")/../../.."
root="$PWD"
[[ -f infra/development/portal-db/compose.yaml ]] || exit 64
scratch="$(mktemp -d)"
witness=''
target_created=0
target=portal_identity_restore_f365_ci
wdbname=portal_recovery_witness_f368
pg(){
 sudo docker compose -p imagine-portal-f368-ci -f infra/development/portal-db/compose.yaml \
 exec -T --user 82:82 postgres sh -ec \
 'export PGPASSWORD="$(cat /run/secrets/database-admin)"; exec "$@"' sh "$@"
}
sql(){ pg psql -X -h 127.0.0.1 -U postgres -d "$1" -v ON_ERROR_STOP=1 -tA -c "$2"; }
tool(){
 sudo docker compose -p imagine-portal-f368-ci -f infra/development/portal-db/compose.yaml \
 run --rm -T --interactive=false \
 -e GITHUB_ACTIONS=true -e RUNNER_ENVIRONMENT=github-hosted \
 -e PORTAL_ENROLLMENT_CI=DISPOSABLE_ONLY \
 -e PORTAL_RESTORE_QUARANTINE_CI=DISPOSABLE_ONLY \
 -v "$root/infra/development/portal-enrollment:/opt/portal-enrollment:ro" \
 -v "$root/infra/development/portal-mail:/opt/portal-mail:ro" \
 -v "$root/infra/development/portal-activation:/opt/portal-activation:ro" \
 -v "$root/infra/development/portal-recovery:/opt/portal-recovery:ro" \
 provision "$@"
}
wsql(){
 sudo docker exec -u postgres "$witness" psql -X -U postgres \
 -d "$wdbname" -v ON_ERROR_STOP=1 -tA -c "$1"
}
cleanup(){
 local code=$?
 trap - EXIT
 set +e
 if [[ -n "$witness" ]]; then sudo docker rm -fv "$witness" >/dev/null 2>&1 || code=1; fi
 if [[ "$target_created" == 1 ]]; then
   pg dropdb -h 127.0.0.1 -U postgres --force "$target" >/dev/null 2>&1 || code=1
 fi
 sudo docker compose -p imagine-portal-f368-ci \
 -f infra/development/portal-db/compose.yaml down --volumes --remove-orphans \
 >/dev/null 2>&1 || code=1
 rm -rf -- "$scratch"
 exit "$code"
}
blocked(){
 local rc=0
 env GITHUB_ACTIONS=true RUNNER_ENVIRONMENT=github-hosted PORTAL_DB_DISPOSABLE_CI=1 \
 python3 infra/development/portal-db/recovery_epoch_preflight.py \
 --snapshot "$scratch/restored.json" --witness "$scratch/witness.json" \
 > "$scratch/verdict.json" || rc=$?
 [[ "$rc" == 65 ]] || { echo 'Stale or missing witness must return 65' >&2; exit 1; }
 grep -q '"status": "BLOCKED"' "$scratch/verdict.json"
 grep -q '"can_authorize_http": false' "$scratch/verdict.json"
}
trap cleanup EXIT

# Cluster A: real Portal identity schema/auth functions, synthetic users only.
sudo python3 infra/development/portal-db/prepare-secrets.py \
 "$root/infra/development/portal-db/.private"
sudo docker compose -p imagine-portal-f368-ci \
 -f infra/development/portal-db/compose.yaml build provision
sudo docker compose -p imagine-portal-f368-ci \
 -f infra/development/portal-db/compose.yaml up -d --wait postgres
sudo docker compose -p imagine-portal-f368-ci \
 -f infra/development/portal-db/compose.yaml run --rm -T --interactive=false \
 provision php provision.php install
tool php /opt/portal-recovery/seed-ci.php
token="$(printf %s 'synthetic-revoked-later-f365-ci-token' | sha256sum | cut -d' ' -f1)"
[[ "$(sql portal_identity_dev "SELECT public.portal_browser_active('$token')")" == t ]]
# CI-only source snapshot manifest. No operational migration is introduced.
sql portal_identity_dev "CREATE TABLE public.portal_restore_epoch_f368 (
 organization varchar(64) NOT NULL, instance varchar(64) NOT NULL,
 generation bigint NOT NULL CHECK(generation>0),
 session_revision bigint NOT NULL CHECK(session_revision>0),
 membership_revision bigint NOT NULL CHECK(membership_revision>0),
 witnessed_sequence bigint NOT NULL CHECK(witnessed_sequence>0),
 PRIMARY KEY(organization,instance));
 INSERT INTO public.portal_restore_epoch_f368 VALUES
 ('org_f368','instance_f368',5,3,7,12)" >/dev/null

# Cluster B: separately initialized PG18, no network/host port, independent
# secret and storage. It is NOT included in Cluster A's pg_dump.
witness_secret="$scratch/witness-admin"
openssl rand -hex 32 > "$witness_secret"
pguid="$(sudo docker run --rm --network none --entrypoint id postgres:18-alpine -u postgres)"
[[ "$pguid" =~ ^[0-9]{1,5}$ ]]
sudo chown "$pguid:$pguid" "$witness_secret"
sudo chmod 0400 "$witness_secret"
witness="$(sudo docker run --detach --network none --memory 512m --cpus 1 \
 --mount "type=bind,source=$witness_secret,target=/run/secrets/witness-admin,readonly" \
 --env "POSTGRES_DB=$wdbname" --env POSTGRES_USER=postgres \
 --env POSTGRES_PASSWORD_FILE=/run/secrets/witness-admin \
 --env POSTGRES_INITDB_ARGS='--auth-host=scram-sha-256' postgres:18-alpine)"
[[ -n "$witness" ]]
[[ "$(sudo docker inspect -f '{{.HostConfig.NetworkMode}}' "$witness")" == none ]]
[[ -z "$(sudo docker port "$witness")" ]]
ready=0
for i in $(seq 1 60); do
 if sudo docker exec "$witness" sh -ec 'test "$(cat /proc/1/comm)" = postgres' 2>/dev/null &&
    [[ "$(wsql 'SELECT current_database()' 2>/dev/null)" == "$wdbname" ]]; then ready=1; break; fi
 sleep 1
done
[[ "$ready" == 1 ]]
sudo docker exec -i -u postgres "$witness" psql -X -U postgres \
 -d "$wdbname" -v ON_ERROR_STOP=1 \
 < infra/development/portal-recovery/f368-witness-pg18.sql >/dev/null
[[ "$(wsql 'SELECT sequence FROM public.portal_f368_independent_witness')" == 12 ]]
[[ "$(wsql "SELECT has_table_privilege('f368_witness_reader','public.portal_f368_independent_witness','UPDATE,DELETE,TRUNCATE')")" == f ]]
[[ "$(wsql "SELECT rolcanlogin FROM pg_roles WHERE rolname='f368_witness_reader'")" == f ]]

# Dump OLDER state; later live revocation advances INDEPENDENT witness B.
pg pg_dump -h 127.0.0.1 -U postgres --format=custom portal_identity_dev > "$scratch/old.dump"
[[ -s "$scratch/old.dump" ]]
sql portal_identity_dev "UPDATE public.portal_auth_sessions SET status='revoked',
 revoked_at=GREATEST(clock_timestamp(),issued_at),updated_at=clock_timestamp()
 WHERE session_sha256='$token' AND status='active'" >/dev/null
[[ "$(sql portal_identity_dev "SELECT public.portal_browser_active('$token')")" == f ]]
wsql "UPDATE public.portal_f368_independent_witness SET session_floor=4,
 sequence=13 WHERE organization='org_f368' AND instance='instance_f368'" >/dev/null
[[ "$(wsql 'SELECT sequence FROM public.portal_f368_independent_witness')" == 13 ]]
if wsql 'UPDATE public.portal_f368_independent_witness SET sequence=12' >/dev/null 2>&1; then
 echo 'External witness rollback must be refused' >&2; exit 1
fi
[[ "$(wsql 'SELECT sequence FROM public.portal_f368_independent_witness')" == 13 ]]

# Isolated restore BENEATH app-connection quarantine; it never becomes live.
[[ "$(sql postgres "SELECT count(*) FROM pg_database WHERE datname='$target'")" == 0 ]]
pg createdb -h 127.0.0.1 -U postgres --template=template0 "$target"
target_created=1
sql postgres "REVOKE CONNECT ON DATABASE $target FROM PUBLIC" >/dev/null
for role in portal_browser_runtime portal_browser_envelope_runtime; do
 [[ "$(sql postgres "SELECT has_database_privilege('$role','$target','CONNECT')")" == f ]]
done
pg pg_restore -h 127.0.0.1 -U postgres --exit-on-error --dbname="$target" < "$scratch/old.dump"
[[ "$(sql "$target" "SELECT public.portal_browser_active('$token')")" == t ]]

# Both JSON observations originate from two different live PG18 clusters.
sql "$target" "SELECT json_build_object('organization',organization,
 'instance',instance,'generation',generation,
 'session_revision',session_revision,'membership_revision',membership_revision,
 'witnessed_sequence',witnessed_sequence)
 FROM public.portal_restore_epoch_f368" > "$scratch/restored.json"
wsql "SELECT json_build_object('organization',organization,
 'instance',instance,'generation_floor',generation_floor,
 'session_floor',session_floor,'membership_floor',membership_floor,
 'sequence',sequence) FROM public.portal_f368_independent_witness" > "$scratch/witness.json"
chmod 0600 "$scratch/restored.json" "$scratch/witness.json"
[[ "$(sql "$target" 'SELECT witnessed_sequence FROM public.portal_restore_epoch_f368')" == 12 ]]
[[ "$(wsql 'SELECT sequence FROM public.portal_f368_independent_witness')" == 13 ]]
blocked
# F369: sign the independent-cluster witness observation with an ephemeral
# Ed25519 key that is never present in the Portal backup. Verify in actual
# dormant Portal Infrastructure code, including forged signature negative.
php infra/development/portal-recovery/test-f369.php \
 "$scratch/restored.json" "$scratch/witness.json"

# Reuse the F365 Core quarantine on restored copy; still NO serving.
tool php /opt/portal-recovery/run-ci.php
[[ "$(sql "$target" "SELECT public.portal_browser_active('$token')")" == f ]]
[[ "$(sql portal_identity_dev "SELECT public.portal_browser_active('$token')")" == f ]]

# Fail closed when independent authority is unavailable; never fall back
# to the restored database's local revisions.
sudo docker stop "$witness" >/dev/null
rm -f -- "$scratch/witness.json"
blocked
echo 'PASS F368: independent PG18 witness advanced beyond old snapshot; stale restore and unavailable witness NO-GO; no serving.'
