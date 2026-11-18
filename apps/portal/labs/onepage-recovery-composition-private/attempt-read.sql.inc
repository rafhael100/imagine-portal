-- Read-only fixture attestation of the whole batch, no payment ID or effects.
CREATE FUNCTION onepage_buyer_lab.fixture_read_attempt_plan(p_session text,p_order uuid)
RETURNS jsonb LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path=pg_catalog,pg_temp SET row_security=on AS $$
DECLARE own jsonb; plan jsonb;
BEGIN
 IF session_user<>'onepage_buyer_runtime' OR current_setting('transaction_isolation')<>'read committed'
 THEN RETURN NULL; END IF;
 own:=onepage_buyer_lab.read_pending_order(p_session,p_order);
 IF own IS NULL THEN RETURN NULL; END IF;
 SELECT jsonb_agg(jsonb_build_object('key',r.line,'due_date',r.due_date,'amount_cents',r.amount_cents,
  'external_reference',r.reference,'attempted',r.attempted,'tuple_xid',r.xmin::text,'account',r.account,
  'environment',r.environment,'customer',r.customer) ORDER BY r.line)
 INTO plan FROM onepage_buyer_lab.fixture_charge_reservations r
 WHERE r.order_id=p_order AND r.contract_scope=(own - ARRAY['buyer','status','quote_snapshot','terms','contract_text']);
 RETURN plan;
END $$;
ALTER FUNCTION onepage_buyer_lab.fixture_read_attempt_plan(text,uuid) OWNER TO onepage_buyer_owner;
REVOKE ALL ON FUNCTION onepage_buyer_lab.fixture_read_attempt_plan(text,uuid) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION onepage_buyer_lab.fixture_read_attempt_plan(text,uuid) TO onepage_buyer_runtime;
