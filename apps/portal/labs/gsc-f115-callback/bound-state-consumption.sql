-- F115: one-use OAuth state MUST be bound to its exact originating
-- Portal MFA session, organization and membership security revision.
-- All SQL is disposable PostgreSQL18 test-only; NOT a production migration.
\set ON_ERROR_STOP on
BEGIN;
CREATE TABLE public.portal_gsc_f115_state_session_bindings (
  state_hash char(64) PRIMARY KEY REFERENCES
    public.portal_gsc_oauth_states(state_hash) ON DELETE RESTRICT,
  session_sha256 char(64) NOT NULL
    CHECK(session_sha256 ~ '^[0-9a-f]{64}$'),
  organization_uuid uuid NOT NULL,
  membership_revision bigint NOT NULL CHECK(membership_revision>0),
  created_at timestamptz NOT NULL DEFAULT clock_timestamp()
);
ALTER TABLE public.portal_gsc_f115_state_session_bindings
 ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.portal_gsc_f115_state_session_bindings
 FORCE ROW LEVEL SECURITY;
REVOKE ALL ON TABLE public.portal_gsc_f115_state_session_bindings
 FROM PUBLIC;
GRANT SELECT(state_hash,session_sha256,organization_uuid,
             membership_revision),
      INSERT(state_hash,session_sha256,organization_uuid,
             membership_revision)
 ON public.portal_gsc_f115_state_session_bindings
 TO portal_gsc_f114_owner;
CREATE POLICY portal_f115_binding_owner
 ON public.portal_gsc_f115_state_session_bindings
 FOR ALL TO portal_gsc_f114_owner USING(true) WITH CHECK(true);

GRANT SELECT(state_hash,tenant_ref,actor_ref,sealed_verifier,
             issued_at,expires_at,consumed_at),
      UPDATE(consumed_at) ON public.portal_gsc_oauth_states
      TO portal_gsc_f114_owner;
GRANT CREATE ON SCHEMA public TO portal_gsc_f114_owner;

-- Compare the SAME MFA/session CONNECT permission after generating PKCE
-- and atomically insert state + immutable original-session binding.
-- The caller supplies only an opaque session digest, selected org UUID,
-- random state hash and AEAD cipher; NEVER tenantRef/actorRef.
CREATE FUNCTION public.portal_gsc_f115_store_bound_state(
 p_session_digest text,p_org uuid,p_state_hash text,
 p_sealed_verifier text,p_issued bigint,p_expires bigint
) RETURNS TABLE(tenant_ref text,actor_ref text)
LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path=pg_catalog,pg_temp SET row_security=on
AS $f115$
DECLARE v_actor uuid;v_org uuid;v_rev bigint;
BEGIN
 IF p_session_digest !~ '^[a-f0-9]{64}$'
 OR p_state_hash !~ '^[a-f0-9]{64}$'
 OR p_sealed_verifier !~ '^v1\.[A-Za-z0-9+/=]{50,255}$'
 OR p_expires-p_issued<>600
 OR p_issued<1600000000 OR p_issued>2200000000
 OR abs(p_issued-floor(extract(epoch FROM clock_timestamp()))::bigint)>5
 THEN RETURN;END IF;
 SELECT p.identity_uuid,p.organization_uuid,p.membership_revision
 INTO v_actor,v_org,v_rev
 FROM public.portal_gsc_f114_connect_principal(p_session_digest,p_org) p;
 IF NOT FOUND OR v_rev<1 THEN RETURN;END IF;
 tenant_ref:='porg-'||replace(v_org::text,'-','');
 actor_ref:='pusr-'||replace(v_actor::text,'-','');
 PERFORM set_config('app.tenant_ref',tenant_ref,true);
 INSERT INTO public.portal_gsc_oauth_states(
   state_hash,tenant_ref,actor_ref,sealed_verifier,issued_at,expires_at)
 VALUES(p_state_hash,tenant_ref,actor_ref,p_sealed_verifier,
        p_issued,p_expires);
 INSERT INTO public.portal_gsc_f115_state_session_bindings(
   state_hash,session_sha256,organization_uuid,membership_revision)
 VALUES(p_state_hash,p_session_digest,p_org,v_rev);
 RETURN NEXT;
END;
$f115$;
ALTER FUNCTION public.portal_gsc_f115_store_bound_state(
 text,uuid,text,text,bigint,bigint) OWNER TO portal_gsc_f114_owner;
REVOKE ALL ON FUNCTION public.portal_gsc_f115_store_bound_state(
 text,uuid,text,text,bigint,bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.portal_gsc_f115_store_bound_state(
 text,uuid,text,text,bigint,bigint) TO portal_gsc_f114_runtime;

-- Callback only consumes when the ORIGINAL bearer is still an active MFA
-- session with current revision and explicit seo.gsc.connect grant.
-- This single function locks the session, membership and connect grant via
-- F114 principal and changes the state row ATOMICALLY. Cross-session
-- substitution, logout, changed membership revision and replay fail.
-- It returns only an encrypted verifier to a restricted PHP backend.
CREATE FUNCTION public.portal_gsc_f115_consume_bound_state(
 p_session_digest text,p_org uuid,p_state_hash text,p_now bigint
) RETURNS TABLE(tenant_ref text,actor_ref text,
                sealed_verifier text,expires_at bigint)
LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path=pg_catalog,pg_temp SET row_security=on
AS $f115$
DECLARE v_actor uuid;v_org uuid;v_rev bigint;
 v_tenant text;v_actor_ref text;
 v_sealed text;v_expires bigint;
BEGIN
 IF p_session_digest !~ '^[a-f0-9]{64}$'
 OR p_state_hash !~ '^[a-f0-9]{64}$'
 OR p_now<1600000000 OR p_now>2200000600
 OR abs(p_now-floor(extract(epoch FROM clock_timestamp()))::bigint)>5
 THEN RETURN;END IF;
 SELECT p.identity_uuid,p.organization_uuid,p.membership_revision
 INTO v_actor,v_org,v_rev
 FROM public.portal_gsc_f114_connect_principal(p_session_digest,p_org) p;
 IF NOT FOUND OR v_rev<1 THEN RETURN;END IF;
 v_tenant:='porg-'||replace(v_org::text,'-','');
 v_actor_ref:='pusr-'||replace(v_actor::text,'-','');

 -- Crucial: original session SHA-256 and membership revision are stored
 -- with the OAuth state, not accepted from the browser as context.
 PERFORM 1 FROM public.portal_gsc_f115_state_session_bindings b
 WHERE b.state_hash=p_state_hash
   AND b.session_sha256=p_session_digest
   AND b.organization_uuid=p_org
   AND b.membership_revision=v_rev;
 IF NOT FOUND THEN RETURN;END IF;

 PERFORM set_config('app.tenant_ref',v_tenant,true);
 UPDATE public.portal_gsc_oauth_states AS st
  SET consumed_at=p_now
 WHERE st.state_hash=p_state_hash
   AND st.tenant_ref=v_tenant AND st.actor_ref=v_actor_ref
   AND st.consumed_at IS NULL
   AND st.issued_at<=p_now AND st.expires_at>=p_now
 RETURNING st.sealed_verifier,st.expires_at
 INTO v_sealed,v_expires;
 IF NOT FOUND THEN RETURN;END IF;
 tenant_ref:=v_tenant;
 actor_ref:=v_actor_ref;
 sealed_verifier:=v_sealed;
 expires_at:=v_expires;
 RETURN NEXT;
END;
$f115$;
ALTER FUNCTION public.portal_gsc_f115_consume_bound_state(
 text,uuid,text,bigint) OWNER TO portal_gsc_f114_owner;
REVOKE ALL ON FUNCTION public.portal_gsc_f115_consume_bound_state(
 text,uuid,text,bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.portal_gsc_f115_consume_bound_state(
 text,uuid,text,bigint) TO portal_gsc_f114_runtime;

REVOKE CREATE ON SCHEMA public FROM portal_gsc_f114_owner;
COMMIT;
