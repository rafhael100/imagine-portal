-- F114: isolated PostgreSQL18 first-party MFA + exact connect grant -> PKCE
-- pending state; no Google, HTTP, real token, production migration.
\set ON_ERROR_STOP on
BEGIN;
CREATE ROLE portal_gsc_f114_owner NOLOGIN NOSUPERUSER
  NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
CREATE ROLE portal_gsc_f114_runtime LOGIN
  PASSWORD 'f114_disposable_runtime_only' NOSUPERUSER NOCREATEDB
  NOCREATEROLE NOINHERIT NOBYPASSRLS;
CREATE ROLE portal_gsc_f114_state_reader LOGIN
  PASSWORD 'f114_disposable_state_reader_only' NOSUPERUSER NOCREATEDB
  NOCREATEROLE NOINHERIT NOBYPASSRLS;
GRANT CONNECT ON DATABASE portal_gsc_f114_lab
  TO portal_gsc_f114_runtime,portal_gsc_f114_state_reader;
GRANT USAGE ON SCHEMA public
  TO portal_gsc_f114_runtime,portal_gsc_f114_owner,
     portal_gsc_f114_state_reader;
GRANT CREATE ON SCHEMA public TO portal_gsc_f114_owner;

-- The reviewer has just the SELECT columns and single immutable key UPDATE
-- grant required for SELECT ... FOR SHARE. Never expose to public web role.
GRANT SELECT(id,status,email_verified_at,mfa_confirmed_at,security_revision),
      UPDATE(id) ON public.portal_identities TO portal_gsc_f114_owner;
GRANT SELECT(id,status,security_revision),
      UPDATE(id) ON public.portal_organizations TO portal_gsc_f114_owner;
GRANT SELECT(id,identity_id,organization_id,status,membership_revision),
      UPDATE(id) ON public.portal_memberships TO portal_gsc_f114_owner;
GRANT SELECT(session_sha256,identity_id,organization_id,status,
             session_purpose,revoked_at,issued_at,expires_at,mfa_verified_at,
             identity_revision,membership_revision,organization_revision),
      UPDATE(session_sha256) ON public.portal_auth_sessions
      TO portal_gsc_f114_owner;
GRANT SELECT(id,membership_id,permission_code),UPDATE(id)
      ON public.portal_membership_grants TO portal_gsc_f114_owner;
GRANT INSERT(state_hash,tenant_ref,actor_ref,sealed_verifier,issued_at,expires_at)
      ON public.portal_gsc_oauth_states TO portal_gsc_f114_owner;

-- FORCE RLS policies bound to NOLOGIN proof owner in disposable DB only.
CREATE POLICY portal_f114_identity_select ON public.portal_identities
 FOR ALL TO portal_gsc_f114_owner USING(true) WITH CHECK(true);
CREATE POLICY portal_f114_org_select ON public.portal_organizations
 FOR ALL TO portal_gsc_f114_owner USING(true) WITH CHECK(true);
CREATE POLICY portal_f114_member_select ON public.portal_memberships
 FOR ALL TO portal_gsc_f114_owner USING(true) WITH CHECK(true);
CREATE POLICY portal_f114_session_select ON public.portal_auth_sessions
 FOR ALL TO portal_gsc_f114_owner USING(true) WITH CHECK(true);
CREATE POLICY portal_f114_grant_select ON public.portal_membership_grants
 FOR ALL TO portal_gsc_f114_owner USING(true) WITH CHECK(true);
CREATE POLICY portal_f114_pending_insert ON public.portal_gsc_oauth_states
 FOR INSERT TO portal_gsc_f114_owner WITH CHECK(true);

-- The state reader can access a pending state only when a trusted server
-- has configured BOTH current tenant and actor locally in this transaction.
-- No state exists outside the fake lab; no real callback token exchange.
GRANT SELECT(state_hash,tenant_ref,actor_ref,sealed_verifier,issued_at,
             expires_at,consumed_at),
      UPDATE(consumed_at) ON public.portal_gsc_oauth_states
      TO portal_gsc_f114_state_reader;
CREATE POLICY portal_f114_state_read ON public.portal_gsc_oauth_states
 AS RESTRICTIVE FOR SELECT TO portal_gsc_f114_state_reader USING(
   tenant_ref=current_setting('app.tenant_ref',true) AND
   actor_ref=current_setting('app.actor_ref',true));
CREATE POLICY portal_f114_state_consume ON public.portal_gsc_oauth_states
 AS RESTRICTIVE FOR UPDATE TO portal_gsc_f114_state_reader USING(
   tenant_ref=current_setting('app.tenant_ref',true) AND
   actor_ref=current_setting('app.actor_ref',true))
 WITH CHECK(
   tenant_ref=current_setting('app.tenant_ref',true) AND
   actor_ref=current_setting('app.actor_ref',true));

CREATE FUNCTION public.portal_gsc_f114_connect_principal(
  p_session_digest text,p_org uuid
) RETURNS TABLE(
  identity_uuid uuid, organization_uuid uuid, membership_revision bigint
)
LANGUAGE sql VOLATILE SECURITY DEFINER
SET search_path=pg_catalog,pg_temp SET row_security=on
AS $f114$
 SELECT i.id,o.id,m.membership_revision::bigint
 FROM public.portal_auth_sessions s
 JOIN public.portal_identities i ON i.id=s.identity_id
 JOIN public.portal_memberships m
   ON m.identity_id=i.id AND m.organization_id=s.organization_id
 JOIN public.portal_organizations o ON o.id=s.organization_id
 JOIN public.portal_membership_grants g ON g.membership_id=m.id
 WHERE p_session_digest ~ '^[0-9a-f]{64}$'
   AND s.session_sha256=p_session_digest
   AND p_org IS NOT NULL AND s.organization_id=p_org
   AND s.status='active' AND s.session_purpose='business'
   AND s.revoked_at IS NULL
   AND s.issued_at<=clock_timestamp() AND s.expires_at>clock_timestamp()
   AND s.expires_at<=s.issued_at+interval '2 hours'
   AND s.mfa_verified_at IS NOT NULL
   AND s.mfa_verified_at>=s.issued_at
   AND s.mfa_verified_at<=clock_timestamp()
   AND i.status='active'
   AND i.email_verified_at IS NOT NULL
   AND i.email_verified_at<=clock_timestamp()
   AND i.mfa_confirmed_at IS NOT NULL
   AND i.mfa_confirmed_at<=clock_timestamp()
   AND m.status='active' AND o.status='active'
   AND s.identity_revision=i.security_revision
   AND s.membership_revision=m.membership_revision
   AND s.organization_revision=o.security_revision
   AND g.permission_code='seo.gsc.connect'
 FOR SHARE OF s,i,m,o,g;
$f114$;
ALTER FUNCTION public.portal_gsc_f114_connect_principal(text,uuid)
 OWNER TO portal_gsc_f114_owner;
REVOKE ALL ON FUNCTION public.portal_gsc_f114_connect_principal(text,uuid)
 FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.portal_gsc_f114_connect_principal(text,uuid)
 TO portal_gsc_f114_runtime;

-- The store function revalidates the same first-party membership/scope
-- while the transaction is open. Neither tenantRef nor actorRef is accepted
-- from the browser or as a function argument. They are derived ONLY
-- from the verified PostgreSQL UUIDs, and are laboratory mapping candidates.
CREATE FUNCTION public.portal_gsc_f114_store_state(
 p_session_digest text,p_org uuid,p_state_hash text,
 p_sealed_verifier text,p_issued bigint,p_expires bigint
) RETURNS TABLE(tenant_ref text,actor_ref text)
LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path=pg_catalog,pg_temp SET row_security=on
AS $f114$
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
 INSERT INTO public.portal_gsc_oauth_states(
   state_hash,tenant_ref,actor_ref,sealed_verifier,issued_at,expires_at)
 VALUES(p_state_hash,tenant_ref,actor_ref,p_sealed_verifier,
        p_issued,p_expires);
 -- PRIMARY KEY(state_hash) itself rejects duplicates. Do not ask the
 -- NOLOGIN owner for SELECT privilege merely for ON CONFLICT inference.
 IF NOT FOUND THEN RETURN;END IF;
 RETURN NEXT;
END;
$f114$;
ALTER FUNCTION public.portal_gsc_f114_store_state(
 text,uuid,text,text,bigint,bigint) OWNER TO portal_gsc_f114_owner;
REVOKE ALL ON FUNCTION public.portal_gsc_f114_store_state(
 text,uuid,text,text,bigint,bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.portal_gsc_f114_store_state(
 text,uuid,text,text,bigint,bigint) TO portal_gsc_f114_runtime;
REVOKE CREATE ON SCHEMA public FROM portal_gsc_f114_owner;
COMMIT;
