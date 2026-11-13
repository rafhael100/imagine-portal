#!/usr/bin/env bash
# F378: independent Worker + actual PG18 old-backup rehearsal. CI ONLY.
set -Eeuo pipefail
umask 077
[[ "$GITHUB_ACTIONS" == true &&
   "$RUNNER_ENVIRONMENT" == github-hosted &&
   "$PORTAL_F378_CI" == DISPOSABLE_ONLY &&
   "$PORTAL_F377_CI" == DISPOSABLE_ONLY ]] || exit 64
cd "$(dirname "$0")/../../../../.."
root="$PWD"
project=imagine-portal-f378-ci
compose=infra/development/portal-db/compose.yaml
base="$root/infra/development/portal-recovery/f376-worker"
scratch="$(mktemp -d)"
chmod 0700 "$scratch"
server_pid=''
restored=0
target=portal_identity_restore_f365_ci

pg(){
 sudo docker compose -p "$project" -f "$compose" exec -T --user 82:82 postgres \
 sh -ec 'export PGPASSWORD="$(cat /run/secrets/database-admin)"; exec "$@"' sh "$@"
}
sql(){ pg psql -X -h 127.0.0.1 -U postgres -d "$1" -v ON_ERROR_STOP=1 -tA -c "$2"; }
tool(){
 sudo docker compose -p "$project" -f "$compose" run --rm -T --interactive=false \
 -e GITHUB_ACTIONS=true -e RUNNER_ENVIRONMENT=github-hosted \
 -e PORTAL_ENROLLMENT_CI=DISPOSABLE_ONLY \
 -e PORTAL_RESTORE_QUARANTINE_CI=DISPOSABLE_ONLY \
 -v "$root/infra/development/portal-enrollment:/opt/portal-enrollment:ro" \
 -v "$root/infra/development/portal-mail:/opt/portal-mail:ro" \
 -v "$root/infra/development/portal-activation:/opt/portal-activation:ro" \
 -v "$root/infra/development/portal-recovery:/opt/portal-recovery:ro" \
 provision "$@"
}
cleanup(){
 local rc=$?
 trap - EXIT
 set +e
 if [[ -n "$server_pid" ]]; then
   kill "$server_pid" 2>/dev/null || true
   wait "$server_pid" 2>/dev/null || true
 fi
 if [[ "$restored" == 1 ]]; then
   pg dropdb -h 127.0.0.1 -U postgres --force "$target" >/dev/null 2>&1 || rc=1
 fi
 sudo docker compose -p "$project" -f "$compose" down --volumes --remove-orphans \
 >/dev/null 2>&1 || rc=1
 rm -rf -- "$scratch"
 exit "$rc"
}
trap cleanup EXIT

sudo python3 infra/development/portal-db/prepare-secrets.py \
 "$root/infra/development/portal-db/.private"
sudo docker compose -p "$project" -f "$compose" build provision
sudo docker compose -p "$project" -f "$compose" up -d --wait postgres
sudo docker compose -p "$project" -f "$compose" run --rm -T --interactive=false \
 provision php provision.php install
tool php /opt/portal-recovery/seed-ci.php
token="$(printf %s 'synthetic-revoked-later-f365-ci-token' | sha256sum | cut -d' ' -f1)"
[[ "$(sql portal_identity_dev "SELECT public.portal_browser_active('$token')")" == t ]]

# CI-specific epoch marker; no operational schema/migration is changed.
sql portal_identity_dev "CREATE TABLE public.portal_restore_epoch_f378(
 organization varchar(64) NOT NULL,
 instance varchar(64) NOT NULL,
 generation bigint NOT NULL CHECK(generation>0),
 session_revision bigint NOT NULL CHECK(session_revision>0),
 membership_revision bigint NOT NULL CHECK(membership_revision>0),
 witnessed_sequence bigint NOT NULL CHECK(witnessed_sequence>0),
 PRIMARY KEY(organization,instance));
 INSERT INTO public.portal_restore_epoch_f378 VALUES
 ('org_f377','instance_f377',5,3,7,12)" >/dev/null
pg pg_dump -h 127.0.0.1 -U postgres --format=custom portal_identity_dev \
 > "$scratch/old.dump"
test -s "$scratch/old.dump"

# Real HTTPS fixture hosting the actual F376 Worker + SQLite schema.
openssl req -x509 -newkey rsa:2048 -nodes -days 1 \
 -subj '/CN=witness.f377.test.invalid' \
 -addext 'subjectAltName=DNS:witness.f377.test.invalid' \
 -keyout "$scratch/leaf.key" -out "$scratch/leaf.crt" >/dev/null 2>&1
chmod 600 "$scratch/leaf.key" "$scratch/leaf.crt"
pin="sha256//$(openssl x509 -in "$scratch/leaf.crt" -noout -pubkey |
 openssl pkey -pubin -outform DER |
 openssl dgst -sha256 -binary | openssl base64 -A)"
[[ "$(printf %s "$pin" | wc -c)" -eq 52 ]]
node "$base/test/f377-https-worker-fixture.mjs" \
 "$scratch" "$scratch/leaf.crt" "$scratch/leaf.key" > "$scratch/fixture.log" 2>&1 &
server_pid=$!
uri='https://witness.f377.test.invalid:18444/v1/portal/recovery-witness'
fetch_witness(){
 curl --fail --silent --show-error --max-time 3 --noproxy '*' \
 --cacert "$scratch/leaf.crt" \
 --resolve witness.f377.test.invalid:18444:127.0.0.1 \
 --pinnedpubkey "$pin" "$uri" -o "$scratch/witness.json"
 chmod 600 "$scratch/witness.json"
}
ready=0
for i in $(seq 1 40); do
 if fetch_witness 2>/dev/null; then ready=1; break; fi
 sleep 0.2
done
[[ "$ready" == 1 ]]
grep -q '"sequence":12' "$scratch/witness.json"
php "$base/test/f378-publish-first.php" "$scratch" "$pin" "$scratch/leaf.crt"
fetch_witness
grep -q '"sequence":13' "$scratch/witness.json"

# Inject SQL failure AFTER independent witness moved forward.
if sql portal_identity_dev "BEGIN;
 UPDATE public.portal_auth_sessions SET status='revoked',
 revoked_at=GREATEST(clock_timestamp(),issued_at),
 updated_at=clock_timestamp()
 WHERE session_sha256='$token' AND status='active';
 SELECT 1/0;
 COMMIT" >/dev/null 2>&1; then
 echo 'F378 forced PostgreSQL transaction did not fail' >&2; exit 1
fi
[[ "$(sql portal_identity_dev "SELECT public.portal_browser_active('$token')")" == t ]]
[[ "$(sql portal_identity_dev 'SELECT witnessed_sequence FROM public.portal_restore_epoch_f378')" == 12 ]]
epoch_json="SELECT json_build_object(
 'organization',organization,'instance',instance,
 'generation',generation,'session_revision',session_revision,
 'membership_revision',membership_revision,
 'witnessed_sequence',witnessed_sequence)
 FROM public.portal_restore_epoch_f378"
sql portal_identity_dev "$epoch_json" > "$scratch/snapshot.json"
chmod 600 "$scratch/snapshot.json"
php "$base/test/f378-verify-witness.php" stale "$scratch/snapshot.json" \
 "$scratch/witness.json" "$scratch/signing-public.hex"

# Single atomic local transaction completes revocation + local epoch advance.
sql portal_identity_dev "BEGIN;
 UPDATE public.portal_auth_sessions SET status='revoked',
 revoked_at=GREATEST(clock_timestamp(),issued_at),
 updated_at=clock_timestamp()
 WHERE session_sha256='$token' AND status='active';
 UPDATE public.portal_restore_epoch_f378 SET
 session_revision=4,witnessed_sequence=13
 WHERE organization='org_f377' AND instance='instance_f377'
 AND session_revision=3 AND witnessed_sequence=12;
 COMMIT" >/dev/null
[[ "$(sql portal_identity_dev "SELECT public.portal_browser_active('$token')")" == f ]]
[[ "$(sql portal_identity_dev 'SELECT witnessed_sequence FROM public.portal_restore_epoch_f378')" == 13 ]]
sql portal_identity_dev "$epoch_json" > "$scratch/snapshot.json"
chmod 600 "$scratch/snapshot.json"
fetch_witness
php "$base/test/f378-verify-witness.php" matched "$scratch/snapshot.json" \
 "$scratch/witness.json" "$scratch/signing-public.hex"

# Restore obsolete PG18 snapshot into an inaccessible offline database.
[[ "$(sql postgres "SELECT count(*) FROM pg_database WHERE datname='$target'")" == 0 ]]
pg createdb -h 127.0.0.1 -U postgres --template=template0 "$target"
restored=1
sql postgres "REVOKE CONNECT ON DATABASE $target FROM PUBLIC" >/dev/null
for role in portal_browser_runtime portal_browser_envelope_runtime; do
 [[ "$(sql postgres "SELECT has_database_privilege('$role','$target','CONNECT')")" == f ]]
done
pg pg_restore -h 127.0.0.1 -U postgres --exit-on-error --dbname="$target" \
 < "$scratch/old.dump"
[[ "$(sql "$target" "SELECT public.portal_browser_active('$token')")" == t ]]
sql "$target" "$epoch_json" > "$scratch/snapshot.json"
chmod 600 "$scratch/snapshot.json"
fetch_witness
php "$base/test/f378-verify-witness.php" stale "$scratch/snapshot.json" \
 "$scratch/witness.json" "$scratch/signing-public.hex"
tool php /opt/portal-recovery/run-ci.php
[[ "$(sql "$target" "SELECT public.portal_browser_active('$token')")" == f ]]
[[ "$(sql portal_identity_dev "SELECT public.portal_browser_active('$token')")" == f ]]
php "$base/test/f378-verify-witness.php" stale "$scratch/snapshot.json" \
 "$scratch/witness.json" "$scratch/signing-public.hex"
echo 'PASS F378: real Worker HMAC witness-first, failed PG18 commit, stale signature denial and isolated restore quarantine; NO SERVING.'
