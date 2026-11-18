-- Inert fixture ONLY. Existing Laravel/PDO/process preflight must run first.
BEGIN;
DO $guard$
BEGIN
 IF current_database()<>'portal_onepage_buyer_ci' OR session_user<>'onepage_lab_bootstrap'
 OR current_user<>session_user OR host(inet_server_addr()) IS DISTINCT FROM '127.0.0.1'
 OR inet_server_port() IS DISTINCT FROM 55439
 OR current_setting('data_directory') !~ '/onepage-pg-lab/data$'
 OR to_regclass('onepage_buyer_lab.pending_orders') IS NULL THEN
  RAISE EXCEPTION 'DISPOSABLE_CONFIRMATION_REQUIRED';
 END IF;
END $guard$;
CREATE ROLE onepage_confirmation_owner NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS NOREPLICATION;
CREATE ROLE onepage_test_reconciler LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS NOREPLICATION;
CREATE ROLE onepage_test_observer LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS NOREPLICATION;
GRANT USAGE ON SCHEMA onepage_buyer_lab TO onepage_confirmation_owner,onepage_test_reconciler,onepage_test_observer;
-- Independently staged fixture feed. Bootstrap seeds scenarios; no runtime can
-- insert or mutate source records. This is not a second confirmation writer.
CREATE TABLE onepage_buyer_lab.synthetic_entry_sources (
 source_id text NOT NULL CHECK(source_id='independent-test-feed.v1'),
 entry_id text NOT NULL CHECK(entry_id ~ '^entry_LAB[a-zA-Z0-9]+$'),
 scope jsonb NOT NULL, confirmed_at timestamptz NOT NULL,
 PRIMARY KEY(source_id,entry_id)
);
CREATE TABLE onepage_buyer_lab.synthetic_source_events (
 source_id text NOT NULL, event_id text NOT NULL CHECK(event_id ~ '^event_LAB[a-zA-Z0-9]+$'),
 entry_id text NOT NULL, source_version bigint NOT NULL CHECK(source_version>0),
 evidence jsonb NOT NULL,
 PRIMARY KEY(source_id,event_id),
 FOREIGN KEY(source_id,entry_id) REFERENCES onepage_buyer_lab.synthetic_entry_sources
);
ALTER TABLE onepage_buyer_lab.synthetic_entry_sources ENABLE ROW LEVEL SECURITY;
ALTER TABLE onepage_buyer_lab.synthetic_entry_sources FORCE ROW LEVEL SECURITY;
ALTER TABLE onepage_buyer_lab.synthetic_source_events ENABLE ROW LEVEL SECURITY;
ALTER TABLE onepage_buyer_lab.synthetic_source_events FORCE ROW LEVEL SECURITY;
REVOKE ALL ON onepage_buyer_lab.synthetic_entry_sources,onepage_buyer_lab.synthetic_source_events FROM PUBLIC;
GRANT SELECT ON onepage_buyer_lab.synthetic_entry_sources,onepage_buyer_lab.synthetic_source_events TO onepage_confirmation_owner;
CREATE POLICY entry_source_read ON onepage_buyer_lab.synthetic_entry_sources FOR SELECT TO onepage_confirmation_owner USING(true);
CREATE POLICY event_source_read ON onepage_buyer_lab.synthetic_source_events FOR SELECT TO onepage_confirmation_owner USING(true);
CREATE TRIGGER immutable_entry_source BEFORE UPDATE OR DELETE ON onepage_buyer_lab.synthetic_entry_sources
 FOR EACH ROW EXECUTE FUNCTION onepage_buyer_lab.reject_order_mutation();
CREATE TRIGGER immutable_event_source BEFORE UPDATE OR DELETE ON onepage_buyer_lab.synthetic_source_events
 FOR EACH ROW EXECUTE FUNCTION onepage_buyer_lab.reject_order_mutation();
CREATE FUNCTION onepage_buyer_lab.observe_synthetic_confirmation(p_source text,p_event text)
RETURNS jsonb LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog,pg_temp SET row_security=on AS $$
 SELECT jsonb_build_object('version',source_version,'digest',encode(sha256(convert_to(evidence::text,'UTF8')),'hex'))
 FROM onepage_buyer_lab.synthetic_source_events
 WHERE session_user='onepage_test_observer' AND source_id=p_source AND event_id=p_event
$$;
ALTER FUNCTION onepage_buyer_lab.observe_synthetic_confirmation(text,text) OWNER TO onepage_confirmation_owner;
REVOKE ALL ON FUNCTION onepage_buyer_lab.observe_synthetic_confirmation(text,text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION onepage_buyer_lab.observe_synthetic_confirmation(text,text) TO onepage_test_observer;
GRANT SELECT ON onepage_buyer_lab.pending_orders TO onepage_confirmation_owner;
CREATE POLICY confirmation_order_read ON onepage_buyer_lab.pending_orders
 FOR SELECT TO onepage_confirmation_owner USING(true);
CREATE TABLE onepage_buyer_lab.synthetic_confirmations (
 order_id uuid NOT NULL REFERENCES onepage_buyer_lab.pending_orders(id),
 revision bigint NOT NULL CHECK(revision>0),
 source_id text NOT NULL, event_id text NOT NULL, entry_id text NOT NULL,
 UNIQUE(source_id,event_id),
 FOREIGN KEY(source_id,event_id) REFERENCES onepage_buyer_lab.synthetic_source_events,
 contract jsonb NOT NULL,
 recorded_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 PRIMARY KEY(order_id,revision)
);
ALTER TABLE onepage_buyer_lab.synthetic_confirmations ENABLE ROW LEVEL SECURITY;
ALTER TABLE onepage_buyer_lab.synthetic_confirmations FORCE ROW LEVEL SECURITY;
REVOKE ALL ON onepage_buyer_lab.synthetic_confirmations FROM PUBLIC;
GRANT SELECT,INSERT ON onepage_buyer_lab.synthetic_confirmations TO onepage_confirmation_owner;
GRANT SELECT ON onepage_buyer_lab.synthetic_confirmations TO onepage_buyer_owner;
CREATE POLICY confirmation_read ON onepage_buyer_lab.synthetic_confirmations
 FOR SELECT TO onepage_confirmation_owner,onepage_buyer_owner USING(true);
CREATE POLICY confirmation_append ON onepage_buyer_lab.synthetic_confirmations
 FOR INSERT TO onepage_confirmation_owner WITH CHECK(session_user='onepage_test_reconciler');
CREATE TRIGGER confirmation_immutable BEFORE UPDATE OR DELETE ON onepage_buyer_lab.synthetic_confirmations
 FOR EACH ROW EXECUTE FUNCTION onepage_buyer_lab.reject_order_mutation();

-- This predicate bounds the FIRST-RELEASE PROOF only. It is not a service
-- suspension policy, nor a decision about first activation with 1-7 days overdue.
CREATE FUNCTION onepage_buyer_lab.confirmation_first_release_in_scope(p_due date,p_today date)
RETURNS boolean LANGUAGE sql IMMUTABLE STRICT SET search_path=pg_catalog,pg_temp AS $$
 SELECT p_due>=p_today
$$;
REVOKE ALL ON FUNCTION onepage_buyer_lab.confirmation_first_release_in_scope(date,date) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION onepage_buyer_lab.confirmation_first_release_in_scope(date,date) TO onepage_buyer_owner;

-- One synthetic decision contract; no receipt marker, PSP or preview identity.
-- The test reconciler is the sole session permitted to append. It supplies a
-- source/event/version/digest only, never buyer JSON or a receipt marker.
CREATE FUNCTION onepage_buyer_lab.append_synthetic_confirmation(p_order uuid,p_source text,p_event text,p_version bigint,p_digest text)
RETURNS text LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path=pg_catalog,pg_temp SET row_security=on AS $$
DECLARE o onepage_buyer_lab.pending_orders%ROWTYPE; previous jsonb; p_contract jsonb;
 staged record; entry_source record;
 scope jsonb; line jsonb; n integer; rev bigint; last_rev bigint;
 observed timestamptz; confirmed timestamptz; accepted_day date;
BEGIN
 IF session_user<>'onepage_test_reconciler' OR current_setting('transaction_isolation')<>'read committed'
 OR p_source IS NULL OR p_event IS NULL OR p_version IS NULL OR p_digest IS NULL THEN RETURN 'denied'; END IF;
 SELECT * INTO staged FROM onepage_buyer_lab.synthetic_source_events WHERE source_id=p_source AND event_id=p_event;
 IF NOT FOUND OR staged.source_version<>p_version
 OR encode(sha256(convert_to(staged.evidence::text,'UTF8')),'hex')<>p_digest THEN RETURN 'denied'; END IF;
 p_contract=staged.evidence;
 SELECT * INTO entry_source FROM onepage_buyer_lab.synthetic_entry_sources
 WHERE source_id=p_source AND entry_id=staged.entry_id;
 IF NOT FOUND OR jsonb_typeof(p_contract) IS DISTINCT FROM 'object'
 OR p_contract->'source' IS DISTINCT FROM jsonb_build_object('id',p_source,'entry',staged.entry_id,'event',p_event)
 OR p_contract->'scope' IS DISTINCT FROM entry_source.scope THEN RETURN 'denied'; END IF;
 -- Serialize idempotency and reversal against the same native order only.
 PERFORM pg_advisory_xact_lock(hashtextextended(p_order::text,91723));
 SELECT * INTO o FROM onepage_buyer_lab.pending_orders WHERE id=p_order;
 IF NOT FOUND OR o.status<>'pending_payment' OR o.quote_snapshot->>'site'<>'onepage'
 OR o.quote_snapshot->'channels' IS DISTINCT FROM '[]'::jsonb THEN RETURN 'denied'; END IF;
 scope=jsonb_build_object('company',o.company,'order',o.id,'quote',o.quote_id,
  'account','synthetic-onepage-v1','environment','synthetic','entry','setup-1',
  'amount_cents',o.quote_snapshot->'illustrative_three_parts_cents'->0,
  'offer_digest',o.offer_digest,'terms_digest',o.terms_digest,'contract_ref',o.contract_ref,
  'contract_revision',o.contract_revision,'contract_digest',o.contract_digest,
  'catalog_version',o.quote_snapshot->>'catalog_version',
  'accepted_at',to_char(o.accepted_at AT TIME ZONE 'UTC','YYYY-MM-DD"T"HH24:MI:SS.US"Z"'));
 IF p_contract->'scope' IS DISTINCT FROM scope
 OR p_contract->>'version' IS DISTINCT FROM 'onepage.synthetic-confirmation.v1'
 OR p_contract->>'origin' IS DISTINCT FROM 'independent-test-reconciliation'
 OR p_contract-'source'-'scope'-'version'-'origin'-'revision'-'state'-'reversal'-'confirmed_at'-'observed_at'-'remaining'<>'{}'::jsonb
 OR (SELECT count(*) FROM jsonb_object_keys(p_contract))<>10
 OR p_contract->>'state' NOT IN ('confirmed','reversed')
 OR p_contract->>'state' IS NULL
 OR p_contract->>'reversal' IS DISTINCT FROM CASE WHEN p_contract->>'state'='confirmed' THEN 'clear' ELSE 'reversed' END
 OR jsonb_typeof(p_contract->'revision') IS DISTINCT FROM 'number'
 OR coalesce(p_contract->>'revision','') !~ '^[1-9][0-9]{0,9}$'
 OR coalesce(p_contract->>'observed_at','') !~ '^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$'
 OR coalesce(p_contract->>'confirmed_at','') !~ '^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$'
 THEN RETURN 'denied'; END IF;
 rev=(p_contract->>'revision')::bigint;
 observed=(p_contract->>'observed_at')::timestamptz;
 confirmed=(p_contract->>'confirmed_at')::timestamptz;
 IF confirmed IS DISTINCT FROM entry_source.confirmed_at OR NOT isfinite(observed) OR NOT isfinite(confirmed) OR confirmed<o.accepted_at
 OR confirmed>observed OR observed>clock_timestamp()
 OR to_char(confirmed AT TIME ZONE 'UTC','YYYY-MM-DD"T"HH24:MI:SS.US"Z"')<>p_contract->>'confirmed_at'
 OR to_char(observed AT TIME ZONE 'UTC','YYYY-MM-DD"T"HH24:MI:SS.US"Z"')<>p_contract->>'observed_at'
 THEN RETURN 'denied'; END IF;
 accepted_day=(o.accepted_at AT TIME ZONE 'America/Sao_Paulo')::date;
 IF jsonb_typeof(p_contract->'remaining') IS DISTINCT FROM 'array'
 OR jsonb_array_length(p_contract->'remaining')<>2 THEN RETURN 'denied'; END IF;
 FOR n IN 0..1 LOOP
  line=p_contract->'remaining'->n;
  IF jsonb_typeof(line) IS DISTINCT FROM 'object'
  OR (SELECT count(*) FROM jsonb_object_keys(line))<>3
  OR line->>'line' IS DISTINCT FROM ('setup-'||(n+2)::text)
  OR line->>'due' IS DISTINCT FROM (accepted_day+(n+1)*30)::text
  OR coalesce(line->>'state','') NOT IN ('pending','confirmed')
  THEN RETURN 'denied'; END IF;
 END LOOP;
 SELECT revision,contract INTO last_rev,previous FROM onepage_buyer_lab.synthetic_confirmations
 WHERE order_id=p_order ORDER BY revision DESC LIMIT 1;
 IF FOUND THEN
  IF rev=last_rev AND previous=p_contract THEN RETURN 'replay'; END IF;
  IF previous->'source'->>'id' IS DISTINCT FROM p_source
   OR previous->'source'->>'entry' IS DISTINCT FROM staged.entry_id
   OR previous->>'state'='reversed' OR rev<>last_rev+1
   OR observed<(previous->>'observed_at')::timestamptz
   OR confirmed<>(previous->>'confirmed_at')::timestamptz THEN RETURN 'denied'; END IF;
 ELSIF rev<>1 OR p_contract->>'state'<>'confirmed' THEN RETURN 'denied'; END IF;
 -- Unique event consumption and decision append are the SAME atomic row.
 INSERT INTO onepage_buyer_lab.synthetic_confirmations(order_id,revision,source_id,event_id,entry_id,contract)
 VALUES(p_order,rev,p_source,p_event,staged.entry_id,p_contract);
 RETURN 'recorded';
EXCEPTION WHEN invalid_datetime_format OR datetime_field_overflow OR invalid_text_representation OR numeric_value_out_of_range OR unique_violation THEN
 RETURN 'denied';
END $$;
ALTER FUNCTION onepage_buyer_lab.append_synthetic_confirmation(uuid,text,text,bigint,text) OWNER TO onepage_confirmation_owner;
REVOKE ALL ON FUNCTION onepage_buyer_lab.append_synthetic_confirmation(uuid,text,text,bigint,text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION onepage_buyer_lab.append_synthetic_confirmation(uuid,text,text,bigint,text) TO onepage_test_reconciler;

CREATE FUNCTION onepage_buyer_lab.synthetic_next_step(p_session text,p_order uuid)
RETURNS jsonb LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path=pg_catalog,pg_temp SET row_security=on AS $$
DECLARE actor record; decision jsonb; today date=(clock_timestamp() AT TIME ZONE 'America/Sao_Paulo')::date;
 closed jsonb='{"next_step":null}'::jsonb;
BEGIN
 IF session_user<>'onepage_buyer_runtime' THEN RETURN closed; END IF;
 SELECT * INTO actor FROM onepage_buyer_lab.principal(p_session);
 IF NOT FOUND THEN RETURN closed; END IF;
 IF NOT EXISTS(SELECT FROM onepage_buyer_lab.pending_orders WHERE id=p_order
  AND company=actor.organization_id AND buyer=actor.identity_id) THEN RETURN closed; END IF;
 SELECT contract INTO decision FROM onepage_buyer_lab.synthetic_confirmations
 WHERE order_id=p_order ORDER BY revision DESC LIMIT 1;
 -- No artificial midnight expiry. Trusted timestamps are source-bound;
 -- first-release positive is confined to remaining installments in good standing.
 IF NOT FOUND OR decision->>'version' IS DISTINCT FROM 'onepage.synthetic-confirmation.v1'
 OR decision->>'origin' IS DISTINCT FROM 'independent-test-reconciliation'
 OR decision->>'state' IS DISTINCT FROM 'confirmed'
 OR decision->>'reversal' IS DISTINCT FROM 'clear'
 OR decision->>'confirmed_at' IS NULL OR decision->>'observed_at' IS NULL
 OR jsonb_typeof(decision->'remaining') IS DISTINCT FROM 'array'
 OR jsonb_array_length(decision->'remaining')<>2
 OR EXISTS(SELECT FROM jsonb_array_elements(decision->'remaining') x
  WHERE x->>'state'='pending' AND NOT onepage_buyer_lab.confirmation_first_release_in_scope((x->>'due')::date,today))
 THEN RETURN closed; END IF;
 RETURN '{"next_step":"briefing"}'::jsonb;
EXCEPTION WHEN invalid_datetime_format OR datetime_field_overflow OR invalid_text_representation THEN
 RETURN closed;
END $$;
ALTER FUNCTION onepage_buyer_lab.synthetic_next_step(text,uuid) OWNER TO onepage_buyer_owner;
REVOKE ALL ON FUNCTION onepage_buyer_lab.synthetic_next_step(text,uuid) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION onepage_buyer_lab.synthetic_next_step(text,uuid) TO onepage_buyer_runtime;
COMMIT;
