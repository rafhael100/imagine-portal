-- Entirely synthetic, disposable, owned by existing test bootstrap. No product table or GRANT.
BEGIN;
CREATE SCHEMA tx_boundary_fixture;
REVOKE ALL ON SCHEMA tx_boundary_fixture FROM PUBLIC;
CREATE TABLE tx_boundary_fixture.binding (
 order_id uuid PRIMARY KEY, active boolean NOT NULL, revision integer NOT NULL
);
INSERT INTO tx_boundary_fixture.binding VALUES('00000000-0000-4000-8000-000000000001',true,1);
CREATE TABLE tx_boundary_fixture.evidence (
 order_id uuid PRIMARY KEY REFERENCES tx_boundary_fixture.binding(order_id),
 revision integer NOT NULL, event text NOT NULL, digest text NOT NULL
);
REVOKE ALL ON ALL TABLES IN SCHEMA tx_boundary_fixture FROM PUBLIC;
CREATE FUNCTION tx_boundary_fixture.confirm(p_order uuid,p_revision integer,p_digest text) RETURNS text
LANGUAGE plpgsql VOLATILE SECURITY INVOKER SET search_path=pg_catalog,pg_temp AS $$
DECLARE b tx_boundary_fixture.binding%ROWTYPE; prior tx_boundary_fixture.evidence%ROWTYPE;
BEGIN
 IF current_setting('transaction_isolation')<>'read committed'
 OR p_order IS DISTINCT FROM '00000000-0000-4000-8000-000000000001'::uuid
 OR p_revision IS DISTINCT FROM 1 OR p_digest IS DISTINCT FROM 'synthetic-fixed-digest' THEN RETURN 'closed'; END IF;
 -- Same stable binding lock for both sides; expected revision is NOT part of the key.
 PERFORM pg_advisory_xact_lock(948001,1);
 SELECT * INTO b FROM tx_boundary_fixture.binding WHERE order_id=p_order;
 IF NOT FOUND OR NOT b.active OR b.revision IS DISTINCT FROM p_revision THEN RETURN 'closed'; END IF;
 INSERT INTO tx_boundary_fixture.evidence VALUES(p_order,p_revision,'synthetic-event',p_digest) ON CONFLICT DO NOTHING;
 IF FOUND THEN RETURN 'recorded'; END IF;
 SELECT * INTO prior FROM tx_boundary_fixture.evidence WHERE order_id=p_order;
 RETURN CASE WHEN prior.revision=p_revision AND prior.digest=p_digest AND prior.event='synthetic-event' THEN 'replay' ELSE 'closed' END;
END $$;
CREATE FUNCTION tx_boundary_fixture.invalidate(p_order uuid,p_kind text) RETURNS text
LANGUAGE plpgsql VOLATILE SECURITY INVOKER SET search_path=pg_catalog,pg_temp AS $$
BEGIN
 IF current_setting('transaction_isolation')<>'read committed'
 OR p_order IS DISTINCT FROM '00000000-0000-4000-8000-000000000001'::uuid
 OR p_kind IS NULL OR p_kind NOT IN ('revoke','revision') THEN RETURN 'closed'; END IF;
 PERFORM pg_advisory_xact_lock(948001,1);
 IF p_kind='revoke' THEN UPDATE tx_boundary_fixture.binding SET active=false WHERE order_id=p_order;
 ELSE UPDATE tx_boundary_fixture.binding SET revision=revision+1 WHERE order_id=p_order; END IF;
 RETURN 'invalidated';
END $$;
-- Fault injection ONLY: shows that a lock used only by the confirmer cannot protect this boundary.
CREATE FUNCTION tx_boundary_fixture.mutant_revoke() RETURNS text
LANGUAGE plpgsql VOLATILE SECURITY INVOKER SET search_path=pg_catalog,pg_temp AS $$
BEGIN
 UPDATE tx_boundary_fixture.binding SET active=false WHERE order_id='00000000-0000-4000-8000-000000000001';
 RETURN 'invalidated';
END $$;
REVOKE ALL ON ALL FUNCTIONS IN SCHEMA tx_boundary_fixture FROM PUBLIC;
COMMIT;
