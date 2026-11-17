-- LAB ONLY: after #922, and only after effective Laravel + Guard::identity preflight.
BEGIN;
DO $guard$
BEGIN
 IF current_database()<>'portal_onepage_buyer_ci' OR current_user<>'onepage_lab_bootstrap'
 OR session_user<>'onepage_lab_bootstrap' OR host(inet_server_addr()) IS DISTINCT FROM '127.0.0.1'
 OR inet_server_port() IS DISTINCT FROM 55439
 OR current_setting('data_directory') !~ '/onepage-pg-lab/data$'
 THEN RAISE EXCEPTION 'DISPOSABLE_IDENTITY_REQUIRED'; END IF;
END $guard$;
-- Additional capability only for the disposable laboratory runtime.
CREATE FUNCTION onepage_buyer_lab.read_pending_order(p_session text,p_order uuid) RETURNS jsonb
LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path=pg_catalog,pg_temp SET row_security=on AS $$
DECLARE actor record; again record; saved onepage_buyer_lab.pending_orders%ROWTYPE;
BEGIN
 IF p_session IS NULL OR p_session !~ '^[a-f0-9]{64}$' OR p_order IS NULL THEN RETURN NULL; END IF;
 SELECT * INTO actor FROM onepage_buyer_lab.principal(p_session);
 IF NOT FOUND THEN RETURN NULL; END IF;
 SELECT * INTO saved FROM onepage_buyer_lab.pending_orders
 WHERE id=p_order AND company=actor.organization_id AND buyer=actor.identity_id AND status='pending_payment';
 IF NOT FOUND THEN RETURN NULL; END IF;
 SELECT * INTO again FROM onepage_buyer_lab.principal(p_session);
 IF NOT FOUND OR again.identity_id<>saved.buyer OR again.organization_id<>saved.company THEN RETURN NULL; END IF;
 RETURN jsonb_build_object('id',saved.id,'quote_id',saved.quote_id,'company',saved.company,'buyer',saved.buyer,
 'status',saved.status,'quote_snapshot',saved.quote_snapshot,'offer_digest',saved.offer_digest,
 'terms',saved.terms,'terms_digest',saved.terms_digest,'contract_ref',saved.contract_ref,
 'contract_revision',saved.contract_revision,'contract_text',saved.contract_text,'contract_digest',saved.contract_digest,
 'accepted_at',to_char(saved.accepted_at AT TIME ZONE 'UTC','YYYY-MM-DD"T"HH24:MI:SS.US"Z"'));
END $$;
ALTER FUNCTION onepage_buyer_lab.read_pending_order(text,uuid) OWNER TO onepage_buyer_owner;
REVOKE ALL ON FUNCTION onepage_buyer_lab.read_pending_order(text,uuid) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION onepage_buyer_lab.read_pending_order(text,uuid) TO onepage_buyer_runtime;
COMMIT;
