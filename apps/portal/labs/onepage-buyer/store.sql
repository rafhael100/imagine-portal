-- LAB ONLY. Run solely after Guard.php validates the exact disposable cluster.
-- Requires unchanged native identity migrations + portal_browser_auth.sql and
-- portal_browser_principal_candidate.sql in this fresh disposable database.
BEGIN;
DO $guard$
BEGIN
 IF current_database()<>'portal_onepage_buyer_ci' OR current_user<>'onepage_lab_bootstrap'
  OR session_user<>'onepage_lab_bootstrap' OR host(inet_server_addr()) IS DISTINCT FROM '127.0.0.1'
  OR inet_server_port() IS DISTINCT FROM 55439
  OR current_setting('data_directory') !~ '/onepage-pg-lab/data$'
 THEN RAISE EXCEPTION 'DISPOSABLE_IDENTITY_REQUIRED'; END IF;
END $guard$;
CREATE ROLE onepage_buyer_owner NOLOGIN NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOINHERIT;
CREATE ROLE onepage_buyer_runtime NOLOGIN NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOINHERIT;
CREATE SCHEMA onepage_buyer_lab;
REVOKE ALL ON SCHEMA onepage_buyer_lab FROM PUBLIC;
GRANT USAGE ON SCHEMA onepage_buyer_lab TO onepage_buyer_owner,onepage_buyer_runtime,portal_browser_owner;
CREATE TABLE onepage_buyer_lab.drafts (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(), company uuid NOT NULL, buyer uuid NOT NULL,
 request_hash text NOT NULL CHECK(request_hash ~ '^[a-f0-9]{64}$'),
 selection jsonb NOT NULL, quote jsonb NOT NULL,
 UNIQUE(company,request_hash)
);
ALTER TABLE onepage_buyer_lab.drafts ENABLE ROW LEVEL SECURITY;
ALTER TABLE onepage_buyer_lab.drafts FORCE ROW LEVEL SECURITY;
REVOKE ALL ON onepage_buyer_lab.drafts FROM PUBLIC;
GRANT SELECT,INSERT ON onepage_buyer_lab.drafts TO onepage_buyer_owner;
CREATE POLICY internal_store ON onepage_buyer_lab.drafts TO onepage_buyer_owner USING(true) WITH CHECK(true);
-- No runtime table grants. Functions derive scope exclusively from native sessions.
GRANT SELECT(role) ON public.portal_memberships TO portal_browser_owner;
CREATE FUNCTION onepage_buyer_lab.principal(p_hash text)
RETURNS TABLE(identity_id uuid,organization_id uuid)
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,pg_temp SET row_security=on AS $$
BEGIN
 -- Locks serialize membership/session/identity/organization revocation until commit.
 PERFORM s.id FROM public.portal_auth_sessions s
 JOIN public.portal_identities i ON i.id=s.identity_id
 JOIN public.portal_memberships m ON m.identity_id=i.id AND m.organization_id=s.organization_id
 JOIN public.portal_organizations o ON o.id=s.organization_id
 WHERE s.session_sha256=p_hash AND m.role='member'
 FOR SHARE OF s,i,m,o;
 IF NOT FOUND THEN RETURN; END IF;
 RETURN QUERY SELECT c.identity_id,c.organization_id FROM public.portal_browser_principal_candidate(p_hash) c;
END $$;
ALTER FUNCTION onepage_buyer_lab.principal(text) OWNER TO portal_browser_owner;
REVOKE ALL ON FUNCTION onepage_buyer_lab.principal(text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION onepage_buyer_lab.principal(text) TO onepage_buyer_owner;
CREATE FUNCTION onepage_buyer_lab.command(p_session text,p_operation text,p_key text,p_id text,p_selection jsonb,p_quote jsonb)
RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,pg_temp SET row_security=on AS $$
DECLARE actor record; again record; draft onepage_buyer_lab.drafts%ROWTYPE;
BEGIN
 SELECT * INTO actor FROM onepage_buyer_lab.principal(p_session);
 IF NOT FOUND THEN RETURN jsonb_build_object('status','denied'); END IF;
 IF p_operation='create' THEN
  IF p_key IS NULL OR p_key !~ '^[a-f0-9]{64}$' OR p_selection IS NULL OR p_quote IS NULL
   OR p_selection->>'site' IS DISTINCT FROM 'onepage' OR p_selection->'channels' IS DISTINCT FROM '[]'::jsonb
   OR p_quote->>'site' IS DISTINCT FROM 'onepage'
   OR p_quote->'real_charge_enabled' IS DISTINCT FROM 'false'::jsonb
   OR p_quote->'public_checkout_authorized' IS DISTINCT FROM 'false'::jsonb
  THEN RETURN jsonb_build_object('status','invalid_selection'); END IF;
  INSERT INTO onepage_buyer_lab.drafts(company,buyer,request_hash,selection,quote)
   VALUES(actor.organization_id,actor.identity_id,p_key,p_selection,p_quote)
   ON CONFLICT(company,request_hash) DO NOTHING;
  SELECT * INTO draft FROM onepage_buyer_lab.drafts WHERE company=actor.organization_id AND request_hash=p_key;
  IF draft.selection IS DISTINCT FROM p_selection OR draft.quote IS DISTINCT FROM p_quote
   THEN RETURN jsonb_build_object('status','conflict'); END IF;
 ELSIF p_operation='resume' THEN
  SELECT * INTO draft FROM onepage_buyer_lab.drafts WHERE company=actor.organization_id AND id::text=p_id;
  IF NOT FOUND THEN RETURN jsonb_build_object('status','denied'); END IF;
 ELSE RETURN jsonb_build_object('status','denied'); END IF;
 SELECT * INTO again FROM onepage_buyer_lab.principal(p_session);
 IF NOT FOUND OR again.identity_id<>actor.identity_id OR again.organization_id<>actor.organization_id
  THEN RAISE EXCEPTION 'ONEPAGE_AUTHORITY_CHANGED'; END IF;
 RETURN jsonb_build_object('status','draft','id',draft.id,'quote',draft.quote,
  'laboratory_only',true,'order_created',false,'payment_requested',false,'site_provisioned',false);
END $$;
ALTER FUNCTION onepage_buyer_lab.command(text,text,text,text,jsonb,jsonb) OWNER TO onepage_buyer_owner;
REVOKE ALL ON FUNCTION onepage_buyer_lab.command(text,text,text,text,jsonb,jsonb) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION onepage_buyer_lab.command(text,text,text,text,jsonb,jsonb) TO onepage_buyer_runtime;
COMMIT;
