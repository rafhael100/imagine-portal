-- Appended before COMMIT to the pinned disposable #926 binding fixture only.
CREATE FUNCTION onepage_buyer_lab.fixture_read_bound_charge(p_session text,p_order uuid,p_line text)
RETURNS jsonb LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path=pg_catalog,pg_temp SET row_security=on AS $$
DECLARE own jsonb; observed jsonb;
BEGIN
 IF session_user<>'onepage_buyer_runtime'
    OR current_setting('transaction_isolation')<>'read committed' THEN RETURN NULL; END IF;
 own := onepage_buyer_lab.read_pending_order(p_session,p_order);
 IF own IS NULL THEN RETURN NULL; END IF;

 SELECT jsonb_build_object(
   'scope',jsonb_build_object(
     'order_id',r.order_id,'company',own->>'company','line',r.line,'quote_id',own->>'quote_id',
     'contract_ref',own->>'contract_ref','contract_revision',own->'contract_revision',
     'offer_digest',own->>'offer_digest','terms_digest',own->>'terms_digest',
     'contract_digest',own->>'contract_digest','catalog_version',own->'quote_snapshot'->>'catalog_version',
     'account',r.account,'environment',r.environment,'reference',r.reference,'customer',r.customer,
     'currency',own->'quote_snapshot'->>'currency','amount_cents',r.amount_cents,'due_date',r.due_date),
   'attempted',r.attempted,'payment_id',b.payment_id,
   'bound_at',to_char(b.bound_at AT TIME ZONE 'UTC','YYYY-MM-DD"T"HH24:MI:SS.US"Z"'))
 INTO observed
 FROM onepage_buyer_lab.fixture_charge_reservations r
 JOIN onepage_buyer_lab.fixture_charge_bindings b ON
   (b.order_id,b.line,b.account,b.environment)=(r.order_id,r.line,r.account,r.environment)
 WHERE r.order_id=p_order AND r.line=p_line AND r.attempted
 AND r.contract_scope=(own - ARRAY['buyer','status','quote_snapshot','terms','contract_text'])
 AND r.environment='sandbox' AND own->'quote_snapshot'->>'currency'='BRL';
 RETURN observed;
END $$;
ALTER FUNCTION onepage_buyer_lab.fixture_read_bound_charge(text,uuid,text) OWNER TO onepage_buyer_owner;
REVOKE ALL ON FUNCTION onepage_buyer_lab.fixture_read_bound_charge(text,uuid,text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION onepage_buyer_lab.fixture_read_bound_charge(text,uuid,text) TO onepage_buyer_runtime;
