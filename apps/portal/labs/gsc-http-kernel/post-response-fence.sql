-- Disposable PG18 HTTP-kernel laboratory ONLY, after the F114/F115 SQL.
-- Reuses the existing business principal and exact original state binding.
-- No production migration, new permission code, identity or auth policy.
\set ON_ERROR_STOP on
BEGIN;
DO $guard$
BEGIN
 IF current_database()<>'portal_gsc_f115_lab'
 OR current_setting('server_version_num')::integer/10000<>18
 THEN RAISE EXCEPTION 'Disposable PG18 GSC laboratory required'; END IF;
END;
$guard$;
GRANT CREATE ON SCHEMA public TO portal_gsc_f114_owner;
CREATE FUNCTION public.portal_gsc_http_kernel_write_principal(
 p_session_digest text,p_org uuid,p_state_hash text
) RETURNS TABLE(tenant_ref text,actor_ref text)
LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path=pg_catalog,pg_temp SET row_security=on
AS $lab$
DECLARE v_actor uuid;v_org uuid;v_rev bigint;v_tenant text;v_actor_ref text;
BEGIN
 IF current_database()<>'portal_gsc_f115_lab'
 OR p_session_digest !~ '^[a-f0-9]{64}$'
 OR p_state_hash !~ '^[a-f0-9]{64}$' THEN RETURN; END IF;
 -- These locks live until the CALLER commits vault + synthetic grant.
 SELECT p.identity_uuid,p.organization_uuid,p.membership_revision
 INTO v_actor,v_org,v_rev
 FROM public.portal_gsc_f114_connect_principal(p_session_digest,p_org) p;
 IF NOT FOUND THEN RETURN; END IF;
 v_tenant:='porg-'||replace(v_org::text,'-','');
 v_actor_ref:='pusr-'||replace(v_actor::text,'-','');
 PERFORM set_config('app.tenant_ref',v_tenant,true);
 PERFORM set_config('app.actor_ref',v_actor_ref,true);
 PERFORM 1 FROM public.portal_gsc_f115_state_session_bindings b
 JOIN public.portal_gsc_oauth_states s ON s.state_hash=b.state_hash
 WHERE b.state_hash=p_state_hash AND b.session_sha256=p_session_digest
 AND b.organization_uuid=v_org AND b.membership_revision=v_rev
 AND s.tenant_ref=v_tenant AND s.actor_ref=v_actor_ref
 AND s.consumed_at IS NOT NULL
 AND s.consumed_at<=floor(extract(epoch FROM clock_timestamp()))::bigint
 AND s.expires_at>=floor(extract(epoch FROM clock_timestamp()))::bigint;
 IF NOT FOUND THEN RETURN; END IF;
 tenant_ref:=v_tenant;actor_ref:=v_actor_ref;
 RETURN NEXT;
END;
$lab$;
ALTER FUNCTION public.portal_gsc_http_kernel_write_principal(text,uuid,text)
 OWNER TO portal_gsc_f114_owner;
REVOKE ALL ON FUNCTION public.portal_gsc_http_kernel_write_principal(text,uuid,text)
 FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.portal_gsc_http_kernel_write_principal(text,uuid,text)
 TO portal_gsc_f114_runtime;

-- Tenant/actor GUCs alone are NEVER authority. Every candidate-ledger read
-- or revocation must prove a live business session with the existing
-- connect grant. INSERT additionally proves the consumed original state.
-- Even a direct SQL caller setting arbitrary GUCs cannot skip this check.
CREATE FUNCTION public.portal_gsc_http_kernel_scope_valid(
 p_tenant text,p_actor text,p_require_state boolean
) RETURNS boolean
LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path=pg_catalog,pg_temp SET row_security=on
AS $scope$
DECLARE v_digest text;v_org text;v_state text;
 v_tenant text;v_actor text;v_identity uuid;v_organization uuid;
BEGIN
 IF current_database()<>'portal_gsc_f115_lab' THEN RETURN false; END IF;
 v_digest:=current_setting('app.gsc_lab_session_digest',true);
 v_org:=current_setting('app.gsc_lab_organization',true);
 v_state:=current_setting('app.gsc_lab_state_hash',true);
 IF v_digest IS NULL OR v_digest !~ '^[a-f0-9]{64}$'
 OR v_org IS NULL OR v_org !~
 '^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'
 THEN RETURN false; END IF;
 IF p_require_state THEN
   IF v_state IS NULL OR v_state !~ '^[a-f0-9]{64}$' THEN RETURN false; END IF;
   SELECT p.tenant_ref,p.actor_ref INTO v_tenant,v_actor
   FROM public.portal_gsc_http_kernel_write_principal(
     v_digest,v_org::uuid,v_state) p;
   IF NOT FOUND THEN RETURN false; END IF;
 ELSE
   SELECT p.identity_uuid,p.organization_uuid INTO v_identity,v_organization
   FROM public.portal_gsc_f114_connect_principal(v_digest,v_org::uuid) p;
   IF NOT FOUND THEN RETURN false; END IF;
   v_tenant:='porg-'||replace(v_organization::text,'-','');
   v_actor:='pusr-'||replace(v_identity::text,'-','');
 END IF;
 RETURN p_tenant=v_tenant AND p_actor=v_actor;
END;
$scope$;
ALTER FUNCTION public.portal_gsc_http_kernel_scope_valid(text,text,boolean)
 OWNER TO portal_gsc_f114_owner;
REVOKE ALL ON FUNCTION public.portal_gsc_http_kernel_scope_valid(text,text,boolean)
 FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.portal_gsc_http_kernel_scope_valid(text,text,boolean)
 TO portal_gsc_f114_runtime;
REVOKE CREATE ON SCHEMA public FROM portal_gsc_f114_owner;

-- Only the disposable runtime gains candidate-ledger access. Existing
-- FORCE RLS tenant policies remain; actor matching is additionally required.
GRANT SELECT,INSERT,UPDATE(revoked_at) ON public.portal_gsc_oauth_token_candidates
 TO portal_gsc_f114_runtime;
GRANT SELECT,INSERT,UPDATE(grant_status,revoked_at) ON public.portal_gsc_property_grants
 TO portal_gsc_f114_runtime;
CREATE POLICY portal_http_lab_token_actor
 ON public.portal_gsc_oauth_token_candidates AS RESTRICTIVE FOR ALL
 TO portal_gsc_f114_runtime
 USING(actor_ref=current_setting('app.actor_ref',true) AND
   public.portal_gsc_http_kernel_scope_valid(tenant_ref,actor_ref,false))
 WITH CHECK(actor_ref=current_setting('app.actor_ref',true) AND
   public.portal_gsc_http_kernel_scope_valid(tenant_ref,actor_ref,false));
CREATE POLICY portal_http_lab_grant_actor
 ON public.portal_gsc_property_grants AS RESTRICTIVE FOR ALL
 TO portal_gsc_f114_runtime
 USING(actor_ref=current_setting('app.actor_ref',true) AND
   public.portal_gsc_http_kernel_scope_valid(tenant_ref,actor_ref,false))
 WITH CHECK(actor_ref=current_setting('app.actor_ref',true) AND
   public.portal_gsc_http_kernel_scope_valid(tenant_ref,actor_ref,false));
CREATE POLICY portal_http_lab_token_state
 ON public.portal_gsc_oauth_token_candidates AS RESTRICTIVE FOR INSERT
 TO portal_gsc_f114_runtime WITH CHECK(
   public.portal_gsc_http_kernel_scope_valid(tenant_ref,actor_ref,true));
CREATE POLICY portal_http_lab_grant_state
 ON public.portal_gsc_property_grants AS RESTRICTIVE FOR INSERT
 TO portal_gsc_f114_runtime WITH CHECK(
   public.portal_gsc_http_kernel_scope_valid(tenant_ref,actor_ref,true));
-- The only UPDATE capability is one-way revocation. Even an authenticated
-- direct SQL caller cannot clear revoked_at or reactivate a revoked grant.
CREATE POLICY portal_http_lab_token_revoke_only
 ON public.portal_gsc_oauth_token_candidates AS RESTRICTIVE FOR UPDATE
 TO portal_gsc_f114_runtime USING(revoked_at IS NULL)
 WITH CHECK(revoked_at IS NOT NULL);
CREATE POLICY portal_http_lab_grant_revoke_only
 ON public.portal_gsc_property_grants AS RESTRICTIVE FOR UPDATE
 TO portal_gsc_f114_runtime USING(revoked_at IS NULL AND grant_status='active')
 WITH CHECK(revoked_at IS NOT NULL AND grant_status='revoked');
COMMIT;
