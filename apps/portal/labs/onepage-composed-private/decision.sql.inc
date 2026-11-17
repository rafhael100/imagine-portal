-- Input only from the disposable bootstrap; runtime cannot choose an outcome.
CREATE TABLE onepage_buyer_lab.fixture_decision_witnesses (
 label text PRIMARY KEY,
 order_id uuid NOT NULL REFERENCES onepage_buyer_lab.pending_orders(id),
 native_scope jsonb NOT NULL,
 bound_entry jsonb NOT NULL,
 setup_lines jsonb NOT NULL CHECK(jsonb_typeof(setup_lines)='array' AND jsonb_array_length(setup_lines)=3),
 projection jsonb NOT NULL,
 staged_at timestamptz NOT NULL DEFAULT clock_timestamp()
);
CREATE TABLE onepage_buyer_lab.fixture_financial_projections (
 order_id uuid NOT NULL REFERENCES onepage_buyer_lab.pending_orders(id),
 version text NOT NULL,
 evaluated_at timestamptz NOT NULL,
 eval_digest text NOT NULL CHECK(eval_digest ~ '^[a-f0-9]{64}$'),
 setup_lines jsonb NOT NULL CHECK(jsonb_typeof(setup_lines)='array' AND jsonb_array_length(setup_lines)=3),
 projection jsonb NOT NULL CHECK(projection->'fixture_only'='true'::jsonb),
 PRIMARY KEY(order_id,version,evaluated_at),
 UNIQUE(order_id,version,eval_digest)
);
ALTER TABLE onepage_buyer_lab.fixture_decision_witnesses ENABLE ROW LEVEL SECURITY;
ALTER TABLE onepage_buyer_lab.fixture_decision_witnesses FORCE ROW LEVEL SECURITY;
ALTER TABLE onepage_buyer_lab.fixture_financial_projections ENABLE ROW LEVEL SECURITY;
ALTER TABLE onepage_buyer_lab.fixture_financial_projections FORCE ROW LEVEL SECURITY;
REVOKE ALL ON onepage_buyer_lab.fixture_decision_witnesses,onepage_buyer_lab.fixture_financial_projections FROM PUBLIC;
CREATE POLICY fixture_decision_read ON onepage_buyer_lab.fixture_decision_witnesses FOR SELECT TO onepage_buyer_owner USING(true);
CREATE POLICY fixture_projection_read ON onepage_buyer_lab.fixture_financial_projections FOR SELECT TO onepage_buyer_owner USING(true);
CREATE POLICY fixture_projection_insert ON onepage_buyer_lab.fixture_financial_projections FOR INSERT TO onepage_buyer_owner WITH CHECK(true);
GRANT SELECT ON onepage_buyer_lab.fixture_decision_witnesses TO onepage_buyer_owner;
GRANT SELECT,INSERT ON onepage_buyer_lab.fixture_financial_projections TO onepage_buyer_owner;

CREATE FUNCTION onepage_buyer_lab.fixture_record_projection(p_session text,p_order uuid)
RETURNS text LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path=pg_catalog,pg_temp SET row_security=on AS $$
DECLARE bound jsonb; own jsonb; items jsonb;
        witness onepage_buyer_lab.fixture_decision_witnesses%ROWTYPE;
        candidates onepage_buyer_lab.fixture_decision_witnesses[];
        prior onepage_buyer_lab.fixture_financial_projections%ROWTYPE;
        v text; d text; evaluated timestamptz;
        keys constant text[]:=ARRAY['fixture_only','version','policy_commit','as_of','order','company',
          'tenant','contract','revision','offer_digest','catalog_version','decision','digest'];
BEGIN
 IF session_user<>'onepage_buyer_runtime' OR current_setting('transaction_isolation')<>'read committed'
 THEN RETURN 'denied'; END IF;
 own := onepage_buyer_lab.read_pending_order(p_session,p_order);
 IF own IS NULL THEN RETURN 'denied'; END IF;
 bound := onepage_buyer_lab.fixture_read_bound_charge(p_session,p_order,'setup-1');
 IF bound IS NULL THEN RETURN 'denied'; END IF;

 -- Read all fixture candidates in one snapshot. Bootstrap is the only writer
 -- and varies them just between isolated negative control phases.
 SELECT array_agg(q.row_value) INTO candidates
 FROM (SELECT w AS row_value FROM onepage_buyer_lab.fixture_decision_witnesses w
       WHERE w.order_id=p_order LIMIT 2) q;
 IF candidates IS NULL THEN RETURN 'pending'; END IF;
 IF cardinality(candidates)<>1 THEN RETURN 'conflict'; END IF;
 witness:=candidates[1];
 IF witness.staged_at>statement_timestamp() OR witness.staged_at<statement_timestamp()-interval '60 seconds'
    OR witness.native_scope IS DISTINCT FROM (own - ARRAY['buyer','status','quote_snapshot','terms','contract_text'])
    OR witness.bound_entry IS DISTINCT FROM bound THEN RETURN 'conflict'; END IF;

 SELECT jsonb_agg(jsonb_build_object('key',r.line,'reference',r.reference,'due_date',r.due_date,
    'amount_cents',r.amount_cents) ORDER BY r.line) INTO items
 FROM (SELECT q.line,q.reference,q.due_date,q.amount_cents
       FROM onepage_buyer_lab.fixture_charge_reservations q
       WHERE q.order_id=p_order AND q.contract_scope=witness.native_scope
       AND (q.account,q.environment,q.customer)=(bound->'scope'->>'account','sandbox',bound->'scope'->>'customer')
       ORDER BY q.line FOR SHARE) r;
 IF items IS DISTINCT FROM witness.setup_lines THEN RETURN 'conflict'; END IF;

 v:=witness.projection->>'version';
 d:=witness.projection->>'digest';
 IF jsonb_typeof(witness.projection) IS DISTINCT FROM 'object'
    OR NOT (witness.projection ?& keys) OR (witness.projection - keys)<>'{}'::jsonb
    OR witness.projection->>'tenant' IS DISTINCT FROM 'lab-native-composed'
    OR v IS DISTINCT FROM 'onepage-financial-condition-fixture.v1'
    OR d IS NULL OR d !~ '^[a-f0-9]{64}$'
    OR witness.projection->'fixture_only' IS DISTINCT FROM 'true'::jsonb
    OR witness.projection->>'policy_commit' IS DISTINCT FROM '60db48be98e9030cf3d4f0d19b9deaa0366aa5c7'
    OR witness.projection->>'order' IS DISTINCT FROM p_order::text
    OR witness.projection->>'company' IS DISTINCT FROM own->>'company'
    OR witness.projection->>'contract' IS DISTINCT FROM own->>'contract_ref'
    OR witness.projection->'revision' IS DISTINCT FROM own->'contract_revision'
    OR witness.projection->>'offer_digest' IS DISTINCT FROM own->>'offer_digest'
    OR witness.projection->>'catalog_version' IS DISTINCT FROM own->'quote_snapshot'->>'catalog_version'
    OR witness.projection->>'decision' IS DISTINCT FROM 'INITIAL_FINANCIAL_CONDITION_MET'
    OR coalesce(witness.projection->>'as_of','') !~ '^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}\.[0-9]{6}Z$'
 THEN RETURN 'conflict'; END IF;
 BEGIN
  evaluated := (witness.projection->>'as_of')::timestamptz;
 EXCEPTION WHEN datetime_field_overflow OR invalid_datetime_format THEN RETURN 'conflict';
 END;
 IF evaluated < (bound->>'bound_at')::timestamptz THEN RETURN 'conflict'; END IF;

 INSERT INTO onepage_buyer_lab.fixture_financial_projections
 VALUES(p_order,v,evaluated,d,items,witness.projection) ON CONFLICT DO NOTHING;
 IF FOUND THEN RETURN 'recorded'; END IF;
 SELECT * INTO prior FROM onepage_buyer_lab.fixture_financial_projections
 WHERE (order_id,version,evaluated_at)=(p_order,v,evaluated);
 IF FOUND AND prior.eval_digest=d AND prior.setup_lines=items AND prior.projection=witness.projection
 THEN RETURN 'replay'; END IF;
 RETURN 'conflict';
END $$;
ALTER FUNCTION onepage_buyer_lab.fixture_record_projection(text,uuid) OWNER TO onepage_buyer_owner;
REVOKE ALL ON FUNCTION onepage_buyer_lab.fixture_record_projection(text,uuid) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION onepage_buyer_lab.fixture_record_projection(text,uuid) TO onepage_buyer_runtime;
