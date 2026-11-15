\set ON_ERROR_STOP on
-- F427 LAB ONLY. NEVER apply to production, existing customer database, or Leaseweb.
DO $guard$
BEGIN
 IF current_database() <> 'portal_purchase_rehearsal' THEN
  RAISE EXCEPTION 'F427 requires disposable portal_purchase_rehearsal DB';
 END IF;
END $guard$;

CREATE SCHEMA perf_monthly_f427;
CREATE EXTENSION IF NOT EXISTS pgcrypto WITH SCHEMA perf_monthly_f427;

CREATE ROLE perf_monthly_f427_issuer LOGIN NOSUPERUSER NOBYPASSRLS NOINHERIT NOCREATEDB NOCREATEROLE;
CREATE ROLE perf_monthly_f427_viewer LOGIN NOSUPERUSER NOBYPASSRLS NOINHERIT NOCREATEDB NOCREATEROLE;
CREATE ROLE perf_monthly_f427_worker LOGIN NOSUPERUSER NOBYPASSRLS NOINHERIT NOCREATEDB NOCREATEROLE;

CREATE TABLE perf_monthly_f427.scope_keys (
 id integer PRIMARY KEY CHECK(id=1),
 secret bytea NOT NULL CHECK(octet_length(secret)=32)
);
INSERT INTO perf_monthly_f427.scope_keys VALUES(1,perf_monthly_f427.gen_random_bytes(32));
REVOKE ALL ON perf_monthly_f427.scope_keys FROM PUBLIC;

CREATE TABLE perf_monthly_f427.monthly_reviews (
 envelope_hash text PRIMARY KEY CHECK(envelope_hash ~ '^[a-f0-9]{64}$'),
 envelope_mac text NOT NULL CHECK(envelope_mac ~ '^[a-f0-9]{64}$'),
 tenant_ref text NOT NULL CHECK(tenant_ref ~ '^org-[a-z0-9-]{3,48}$'),
 order_ref text NOT NULL CHECK(order_ref ~ '^order-[a-z0-9-]{3,48}$'),
 report_version integer NOT NULL CHECK(report_version BETWEEN 1 AND 1000000),
 report_sha256 text NOT NULL CHECK(report_sha256 ~ '^[a-f0-9]{64}$'),
 candidate_sha256 text NOT NULL CHECK(candidate_sha256 ~ '^[a-f0-9]{64}$'),
 period_start date NOT NULL CHECK(EXTRACT(day FROM period_start)=1),
 amount_cents integer NOT NULL CHECK(amount_cents BETWEEN 17000 AND 500000000),
 issued_at timestamptz NOT NULL,
 expires_at timestamptz NOT NULL,
 state text NOT NULL DEFAULT 'issued' CHECK(state IN ('issued','consumed','revoked')),
 consumed_at timestamptz,
 CHECK(expires_at>issued_at AND expires_at<=issued_at+interval '15 minutes'),
 CHECK((state='consumed' AND consumed_at IS NOT NULL) OR
       (state IN ('issued','revoked') AND consumed_at IS NULL))
);
-- A revoked report can be superseded by a corrected acknowledged version.
-- An issued or already consumed month cannot be issued again.
CREATE UNIQUE INDEX f427_active_monthly_order
 ON perf_monthly_f427.monthly_reviews(tenant_ref,order_ref,period_start)
 WHERE state IN ('issued','consumed');
ALTER TABLE perf_monthly_f427.monthly_reviews ENABLE ROW LEVEL SECURITY;
ALTER TABLE perf_monthly_f427.monthly_reviews FORCE ROW LEVEL SECURITY;

CREATE FUNCTION perf_monthly_f427.viewer_scope(row_tenant text)
 RETURNS boolean LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog
AS $body$
 SELECT session_user='perf_monthly_f427_viewer'
   AND current_setting('imagine.f427_tenant',true)=row_tenant
   AND EXISTS (
    SELECT 1 FROM perf_monthly_f427.scope_keys k
     WHERE k.id=1 AND encode(perf_monthly_f427.hmac(
      convert_to('imagine-f427-synthetic-tenant:'||row_tenant,'UTF8'),
      k.secret,'sha256'),'hex')=current_setting('imagine.f427_mac',true)
   );
$body$;
REVOKE ALL ON FUNCTION perf_monthly_f427.viewer_scope(text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION perf_monthly_f427.viewer_scope(text) TO perf_monthly_f427_viewer;

CREATE POLICY f427_viewer_own ON perf_monthly_f427.monthly_reviews
 FOR SELECT TO perf_monthly_f427_viewer
 USING(perf_monthly_f427.viewer_scope(tenant_ref));
CREATE POLICY f427_issuer_only ON perf_monthly_f427.monthly_reviews
 FOR INSERT TO perf_monthly_f427_issuer
 WITH CHECK(state='issued' AND consumed_at IS NULL
  AND issued_at<=now() AND issued_at>now()-interval '30 seconds'
  AND expires_at>now() AND expires_at<=now()+interval '15 minutes'
  AND period_start < date_trunc('month',now())::date);
REVOKE ALL ON perf_monthly_f427.monthly_reviews FROM PUBLIC;
GRANT USAGE ON SCHEMA perf_monthly_f427 TO
 perf_monthly_f427_issuer,perf_monthly_f427_viewer,perf_monthly_f427_worker;
GRANT INSERT(envelope_hash,envelope_mac,tenant_ref,order_ref,
 report_version,report_sha256,candidate_sha256,period_start,
 amount_cents,issued_at,expires_at)
 ON perf_monthly_f427.monthly_reviews TO perf_monthly_f427_issuer;
GRANT SELECT ON perf_monthly_f427.monthly_reviews TO perf_monthly_f427_viewer;

-- Worker has no table SELECT/UPDATE. This narrow function is the only
-- durable transition; all fields are exact (including tenant and MAC).
-- HMAC cryptographic verification and official platform evidence remain
-- the caller's separate duty. Returning TRUE is NOT billing permission.
CREATE FUNCTION perf_monthly_f427.consume_review(
 p_envelope_hash text,p_mac text,p_tenant text,p_order text,
 p_period date,p_report_version integer,p_report_sha text,
 p_candidate_sha text,p_amount_cents integer)
 RETURNS boolean LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog
AS $body$
DECLARE changed integer;
BEGIN
 IF session_user<>'perf_monthly_f427_worker'
    OR p_envelope_hash !~ '^[a-f0-9]{64}$'
    OR p_mac !~ '^[a-f0-9]{64}$' THEN
  RETURN false;
 END IF;
 UPDATE perf_monthly_f427.monthly_reviews
    SET state='consumed',consumed_at=now()
  WHERE envelope_hash=p_envelope_hash
    AND envelope_mac=p_mac
    AND tenant_ref=p_tenant
    AND order_ref=p_order
    AND period_start=p_period
    AND report_version=p_report_version
    AND report_sha256=p_report_sha
    AND candidate_sha256=p_candidate_sha
    AND amount_cents=p_amount_cents
    AND state='issued' AND expires_at>now()
    AND issued_at<=now()
    AND period_start<date_trunc('month',now())::date;
 GET DIAGNOSTICS changed=ROW_COUNT;
 RETURN changed=1;
END;
$body$;
REVOKE ALL ON FUNCTION perf_monthly_f427.consume_review(text,text,text,text,date,integer,text,text,integer) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION perf_monthly_f427.consume_review(text,text,text,text,date,integer,text,text,integer)
 TO perf_monthly_f427_worker;

CREATE FUNCTION perf_monthly_f427.revoke_review(p_hash text,p_mac text)
 RETURNS boolean LANGUAGE plpgsql VOLATILE SECURITY DEFINER SET search_path=pg_catalog
AS $body$
DECLARE changed integer;
BEGIN
 IF session_user<>'perf_monthly_f427_worker' THEN RETURN false; END IF;
 UPDATE perf_monthly_f427.monthly_reviews SET state='revoked'
  WHERE envelope_hash=p_hash AND envelope_mac=p_mac AND state='issued';
 GET DIAGNOSTICS changed=ROW_COUNT;
 RETURN changed=1;
END;
$body$;
REVOKE ALL ON FUNCTION perf_monthly_f427.revoke_review(text,text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION perf_monthly_f427.revoke_review(text,text) TO perf_monthly_f427_worker;

-- No invoice, PSP, fiscal, ad-platform, webhook, or public API functions.
