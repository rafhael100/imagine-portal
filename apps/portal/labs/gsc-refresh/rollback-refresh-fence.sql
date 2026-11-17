-- Remove only the laboratory renewal capability before the existing rollback.
\set ON_ERROR_STOP on
BEGIN;
DO $guard$
BEGIN
 IF current_database()<>'portal_gsc_f115_lab' THEN RAISE EXCEPTION 'Disposable GSC laboratory required'; END IF;
END;
$guard$;
ALTER POLICY portal_http_lab_token_state ON public.portal_gsc_oauth_token_candidates
 WITH CHECK(public.portal_gsc_http_kernel_scope_valid(tenant_ref,actor_ref,true));
ALTER POLICY portal_http_lab_grant_state ON public.portal_gsc_property_grants
 WITH CHECK(public.portal_gsc_http_kernel_scope_valid(tenant_ref,actor_ref,true));
DROP TRIGGER portal_gsc_refresh_token_completed_guard ON public.portal_gsc_oauth_token_candidates;
DROP TRIGGER portal_gsc_refresh_grant_completed_guard ON public.portal_gsc_property_grants;
DROP OWNED BY portal_gsc_refresh_owner RESTRICT;
DROP ROLE portal_gsc_refresh_owner;
COMMIT;
