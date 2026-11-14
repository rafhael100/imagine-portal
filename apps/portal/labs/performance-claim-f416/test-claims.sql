\set ON_ERROR_STOP on
DO $guard$
BEGIN IF current_database()<>'portal_purchase_rehearsal' THEN
 RAISE EXCEPTION 'Wrong DB'; END IF; END $guard$;

-- Insert via an issuer without superuser, BYPASSRLS or role inheritance.
SET SESSION AUTHORIZATION perf_claim_f416_issuer;
INSERT INTO perf_claim_f416.activation_claims(
 envelope_hash,envelope_mac,tenant_ref,order_ref,policy_revision,
 channel_set_hash,activation_cents,issued_at,expires_at)
VALUES
 (repeat('a',64),repeat('c',64),'org-alpha-001','order-alpha-001',7,repeat('1',64),98000,
  now()-interval '1 minute',now()+interval '14 minutes'),
 (repeat('b',64),repeat('d',64),'org-beta-001','order-beta-001',7,repeat('2',64),49000,
  now()-interval '1 minute',now()+interval '14 minutes'),
 (repeat('e',64),repeat('f',64),'org-alpha-001','order-alpha-002',7,repeat('3',64),147000,
  now()-interval '1 minute',now()+interval '14 minutes');

-- The same tenant/order/policy cannot receive a second envelope.
DO $dedup$
DECLARE blocked boolean:=false;
BEGIN
 BEGIN
  INSERT INTO perf_claim_f416.activation_claims(
   envelope_hash,envelope_mac,tenant_ref,order_ref,policy_revision,
   channel_set_hash,activation_cents,issued_at,expires_at)
  VALUES(repeat('9',64),repeat('8',64),'org-alpha-001','order-alpha-001',7,
   repeat('7',64),98000,now(),now()+interval '10 minutes');
 EXCEPTION WHEN unique_violation THEN blocked:=true;
 END;
 IF NOT blocked THEN RAISE EXCEPTION 'CRITICAL: duplicate order/policy claim allowed'; END IF;
END $dedup$;

DO $limits$
DECLARE blocked boolean:=false;
BEGIN
 BEGIN
  INSERT INTO perf_claim_f416.activation_claims(
   envelope_hash,envelope_mac,tenant_ref,order_ref,policy_revision,
   channel_set_hash,activation_cents,issued_at,expires_at)
  VALUES(repeat('6',64),repeat('7',64),'org-alpha-001','order-alpha-003',7,
   repeat('5',64),50000,now(),now()+interval '16 minutes');
 EXCEPTION WHEN check_violation THEN blocked:=true;
 END;
 IF NOT blocked THEN RAISE EXCEPTION 'CRITICAL: invalid activation value/TTL accepted'; END IF;
END $limits$;
RESET SESSION AUTHORIZATION;

-- Owner computes a one-time synthetic MAC in the isolated DB, without
-- publishing the secret. The application role cannot read this key table.
SELECT encode(perf_claim_f416.hmac(
 convert_to('imagine-f416-synthetic-tenant:org-alpha-001','UTF8'),
 key_bytes,'sha256'),'hex') AS owner_scope_mac
 FROM perf_claim_f416.scope_keys WHERE id=1 \gset

SET SESSION AUTHORIZATION perf_claim_f416_app;
DO $unauth$
DECLARE n bigint;
BEGIN
 SELECT count(*) INTO n FROM perf_claim_f416.activation_claims;
 IF n<>0 THEN RAISE EXCEPTION 'CRITICAL: unscoped app can list envelopes'; END IF;
END $unauth$;
SELECT set_config('imagine.f416_tenant','org-alpha-001',false);
SELECT set_config('imagine.f416_tenant_mac',repeat('0',64),false);
DO $forged$
DECLARE n bigint;
BEGIN
 SELECT count(*) INTO n FROM perf_claim_f416.activation_claims;
 IF n<>0 THEN RAISE EXCEPTION 'CRITICAL: fake MAC reveals tenant'; END IF;
END $forged$;
SELECT set_config('imagine.f416_tenant_mac',:'owner_scope_mac',false);
DO $owner$
DECLARE n bigint; blocked boolean:=false;
BEGIN
 SELECT count(*) INTO n FROM perf_claim_f416.activation_claims;
 IF n<>2 THEN RAISE EXCEPTION 'CRITICAL: signed tenant scope expected 2 rows, got %',n; END IF;
 BEGIN
  UPDATE perf_claim_f416.activation_claims SET state='consumed',
   consumed_at=now() WHERE envelope_hash=repeat('a',64);
 EXCEPTION WHEN insufficient_privilege THEN blocked:=true;
 END;
 IF NOT blocked THEN RAISE EXCEPTION 'CRITICAL: app can consume claim'; END IF;
 blocked:=false;
 BEGIN
  PERFORM key_bytes FROM perf_claim_f416.scope_keys WHERE id=1;
 EXCEPTION WHEN insufficient_privilege THEN blocked:=true;
 END;
 IF NOT blocked THEN RAISE EXCEPTION 'CRITICAL: app can read DB MAC secret'; END IF;
END $owner$;
-- Switching GUCs cannot mint a valid MAC for another organization.
SELECT set_config('imagine.f416_tenant','org-beta-001',false);
DO $cross$
DECLARE n bigint;
BEGIN SELECT count(*) INTO n FROM perf_claim_f416.activation_claims;
 IF n<>0 THEN RAISE EXCEPTION 'CRITICAL: cross-tenant leak'; END IF;
END $cross$;
RESET SESSION AUTHORIZATION;

-- Worker is a separate restricted login. One conditional UPDATE is atomic.
-- Its caller must independently verify the signed F415 envelope; the SQL
-- gate only proves persistence, exact opaque MAC matching and one-time use.
SET SESSION AUTHORIZATION perf_claim_f416_worker;
DO $one_use$
DECLARE n bigint;
BEGIN
 UPDATE perf_claim_f416.activation_claims
 SET state='consumed',consumed_at=now()
 WHERE envelope_hash=repeat('a',64)
   AND envelope_mac=repeat('0',64) AND state='issued' AND expires_at>now();
 GET DIAGNOSTICS n=ROW_COUNT;
 IF n<>0 THEN RAISE EXCEPTION 'CRITICAL: wrong envelope MAC consumed claim'; END IF;

 UPDATE perf_claim_f416.activation_claims
 SET state='consumed',consumed_at=now()
 WHERE envelope_hash=repeat('a',64)
   AND envelope_mac=repeat('c',64) AND state='issued' AND expires_at>now();
 GET DIAGNOSTICS n=ROW_COUNT;
 IF n<>1 THEN RAISE EXCEPTION 'CRITICAL: first signed claim was not consumed'; END IF;

 UPDATE perf_claim_f416.activation_claims
 SET state='consumed',consumed_at=now()
 WHERE envelope_hash=repeat('a',64)
   AND envelope_mac=repeat('c',64) AND state='issued' AND expires_at>now();
 GET DIAGNOSTICS n=ROW_COUNT;
 IF n<>0 THEN RAISE EXCEPTION 'CRITICAL: replay consumed twice'; END IF;

 UPDATE perf_claim_f416.activation_claims
 SET state='revoked'
 WHERE envelope_hash=repeat('e',64)
   AND envelope_mac=repeat('f',64) AND state='issued';
 GET DIAGNOSTICS n=ROW_COUNT;
 IF n<>1 THEN RAISE EXCEPTION 'CRITICAL: unable to revoke pending claim'; END IF;

 UPDATE perf_claim_f416.activation_claims
 SET state='consumed',consumed_at=now()
 WHERE envelope_hash=repeat('e',64)
   AND envelope_mac=repeat('f',64) AND state='issued';
 GET DIAGNOSTICS n=ROW_COUNT;
 IF n<>0 THEN RAISE EXCEPTION 'CRITICAL: revoked claim was consumed'; END IF;

 SELECT count(*) INTO n FROM perf_claim_f416.activation_claims
 WHERE state='consumed';
 IF n<>1 THEN RAISE EXCEPTION 'CRITICAL: expected exactly one consumption'; END IF;
END $one_use$;
RESET SESSION AUTHORIZATION;

DO $final$
DECLARE secure boolean; n bigint;
BEGIN
 SELECT c.relrowsecurity AND c.relforcerowsecurity INTO secure
  FROM pg_class c WHERE c.oid='perf_claim_f416.activation_claims'::regclass;
 IF secure IS DISTINCT FROM true THEN
  RAISE EXCEPTION 'CRITICAL: row security not forced'; END IF;
 SELECT count(*) INTO n FROM pg_roles
 WHERE rolname IN ('perf_claim_f416_app','perf_claim_f416_issuer','perf_claim_f416_worker')
 AND rolcanlogin AND NOT rolsuper AND NOT rolbypassrls AND NOT rolinherit;
 IF n<>3 THEN RAISE EXCEPTION 'CRITICAL: restricted login roles missing'; END IF;
 SELECT count(*) INTO n FROM perf_claim_f416.activation_claims;
 IF n<>3 THEN RAISE EXCEPTION 'CRITICAL: claim row count changed'; END IF;
END $final$;

\echo 'PASS F416: forced individual tenant RLS, single-use consumption, dedup, revocation, invalid MAC and no secrets.'
