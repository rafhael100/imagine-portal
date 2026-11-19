-- REVIEW ONLY: not an installer/migration. Never executed in this task.
-- Requires isolated DB, owned non-writable schema, FORCE RLS tables and policies.
-- admissions: account_ref text, order_id text, payment_id text, revision text,
-- environment text, active boolean; all NOT NULL; PK(account_ref,order_id),
-- UNIQUE(account_ref,payment_id). Immutable binding/revision; revoke is terminal.
-- inbox: account_ref,event_id,event_type,payment_id,order_id,revision,payload_sha256
-- all text NOT NULL; PK(account_ref,event_id); received_at defaults clock_timestamp().
-- No delete/rearm/replacement of admissions or inbox records during the window.
-- A separate reviewed installer must set function owner onepage_ingress_owner
-- (NOLOGIN, NOSUPERUSER, NOBYPASSRLS), and remove PUBLIC EXECUTE in the SAME DDL
-- transaction before commit. Capture/revoke logins get only their exact function
-- EXECUTE, no table access, SET ROLE, ownership or schema CREATE. No grants here.
-- Definer: admission SELECT + UPDATE(active) for locks/revoke; inbox SELECT/INSERT,
-- never inbox UPDATE/DELETE. Restrictive policies bind the isolated case/account.
-- Caller attestation must verify these facts plus actual PDO/cluster/catalog pins.
-- These definitions alone are NOT deployable; installation/ACL proof is missing.

CREATE FUNCTION onepage_ingress_lab.capture(
 a text,o text,p text,r text,env text,e text,k text,ep text,h text
) RETURNS text LANGUAGE plpgsql SECURITY DEFINER
SET search_path = pg_catalog, pg_temp AS $$
DECLARE binding onepage_ingress_lab.admissions%ROWTYPE;
 saved onepage_ingress_lab.inbox%ROWTYPE;
 inserted integer;
BEGIN
 IF current_user <> 'onepage_ingress_owner'
    OR session_user <> 'onepage_ingress_capture' THEN
   RAISE EXCEPTION 'CAPTURE_ROLE_REFUSED';
 END IF;
 IF a IS NULL OR o IS NULL OR p IS NULL OR r IS NULL OR env IS NULL
    OR e IS NULL OR k IS NULL OR ep IS NULL OR h IS NULL
    OR env <> 'sandbox' OR ep <> p
    OR e !~ '^evt_[A-Za-z0-9_&-]{8,128}$'
    OR h !~ '^[a-f0-9]{64}$'
    OR k NOT IN ('PAYMENT_CREATED','PAYMENT_CONFIRMED','PAYMENT_RECEIVED') THEN
   RETURN 'unarmed';
 END IF;
 -- Same row and lock order as revoke; lock remains held through caller COMMIT.
 SELECT * INTO binding FROM onepage_ingress_lab.admissions
 WHERE account_ref=a AND order_id=o FOR UPDATE;
 IF NOT FOUND THEN RETURN 'unarmed'; END IF;
 IF binding.active IS DISTINCT FROM true OR binding.environment <> 'sandbox'
    OR binding.payment_id <> p OR binding.revision <> r THEN
   RETURN 'unarmed';
 END IF;
 INSERT INTO onepage_ingress_lab.inbox
   (account_ref,event_id,event_type,payment_id,order_id,revision,payload_sha256)
 VALUES (a,e,k,p,o,r,h) ON CONFLICT (account_ref,event_id) DO NOTHING;
 GET DIAGNOSTICS inserted = ROW_COUNT;
 IF inserted=1 THEN RETURN 'recorded'; END IF;
 SELECT * INTO saved FROM onepage_ingress_lab.inbox
 WHERE account_ref=a AND event_id=e;
 IF NOT FOUND THEN RAISE EXCEPTION 'DUPLICATE_NOT_VISIBLE'; END IF;
 IF saved.event_type=k AND saved.payment_id=p AND saved.order_id=o
    AND saved.revision=r AND saved.payload_sha256=h THEN RETURN 'replay'; END IF;
 RETURN 'conflict';
END $$;

CREATE FUNCTION onepage_ingress_lab.revoke(a text,o text,p text,r text)
RETURNS boolean LANGUAGE plpgsql SECURITY DEFINER
SET search_path = pg_catalog, pg_temp AS $$
DECLARE binding onepage_ingress_lab.admissions%ROWTYPE;
BEGIN
 IF current_user <> 'onepage_ingress_owner'
    OR session_user <> 'onepage_ingress_revoker' THEN
   RAISE EXCEPTION 'REVOKE_ROLE_REFUSED';
 END IF;
 IF a IS NULL OR o IS NULL OR p IS NULL OR r IS NULL THEN RETURN false; END IF;
 SELECT * INTO binding FROM onepage_ingress_lab.admissions
 WHERE account_ref=a AND order_id=o FOR UPDATE;
 IF NOT FOUND THEN RETURN false; END IF;
 IF binding.environment <> 'sandbox' OR binding.payment_id <> p
    OR binding.revision <> r THEN RETURN false; END IF;
 UPDATE onepage_ingress_lab.admissions SET active=false
 WHERE account_ref=a AND order_id=o;
 RETURN true; -- Revoker caller must commit before reporting revocation complete.
END $$;
