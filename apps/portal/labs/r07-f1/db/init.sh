#!/bin/sh
set -eu
# Executes only while initializing disposable portal_r07_f1.
[ "$POSTGRES_DB" = "portal_r07_f1" ] || exit 11
[ "$POSTGRES_USER" = "lab_owner" ] || exit 11
[ -n "$R07_AUTH_PASSWORD" ] || exit 11
[ -n "$R07_ACTOR_A_DIGEST" ] || exit 11
[ -n "$R07_ACTOR_B_DIGEST" ] || exit 11

psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -v ON_ERROR_STOP=1 \
  -v auth_password="$R07_AUTH_PASSWORD" <<'PSQL'
SELECT format('CREATE ROLE r07_authority LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION PASSWORD %L', :'auth_password') \gexec
PSQL

psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -v ON_ERROR_STOP=1 -f /lab/schema.sql

psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -v ON_ERROR_STOP=1 \
  -v digest_a="$R07_ACTOR_A_DIGEST" -v digest_b="$R07_ACTOR_B_DIGEST" <<'PSQL'
INSERT INTO r07.account(actor_id, active, session_epoch)
  VALUES ('actor-a', true, 1), ('actor-b', true, 1);
INSERT INTO r07.session(verifier_sha256, actor_id, session_epoch, active, expires_at)
  VALUES
  (:'digest_a', 'actor-a', 1, true, clock_timestamp() + interval '2 hours'),
  (:'digest_b', 'actor-b', 1, true, clock_timestamp() + interval '2 hours');
INSERT INTO r07.membership(organization_id, actor_id, can_read, can_write)
  VALUES ('org-a', 'actor-a', true, true),
         ('org-b', 'actor-b', true, true);
INSERT INTO r07.resource_index(organization_id, resource_id)
  VALUES ('org-a','resource-a'), ('org-b','resource-b');
INSERT INTO r07.private_resource(organization_id, resource_id, payload)
  VALUES ('org-a','resource-a','SYNTHETIC_A_ONLY'),
         ('org-b','resource-b','SYNTHETIC_B_ONLY');
PSQL
