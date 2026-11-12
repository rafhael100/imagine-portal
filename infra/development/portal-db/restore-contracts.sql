-- F349: database-local restore assertions, no data mutation and no secrets.
-- Run exclusively against a synthetic, newly restored disposable CI database.
DO $restore_contract$
DECLARE
    mismatches bigint;
BEGIN
    SELECT count(*) INTO mismatches
      FROM (VALUES
        ('public.portal_browser_proof_ok(text,text)','portal_browser_owner',false,false),
        ('public.portal_browser_claim_attempt(text,text)','portal_browser_owner',true,false),
        ('public.portal_browser_material(text,uuid)','portal_browser_owner',true,false),
        ('public.portal_browser_begin(text,uuid,text,text,text)','portal_browser_owner',true,false),
        ('public.portal_browser_pending(text)','portal_browser_owner',true,false),
        ('public.portal_browser_complete(text,text,bigint,text,text)','portal_browser_owner',true,false),
        ('public.portal_browser_active(text)','portal_browser_owner',true,false),
        ('public.portal_browser_permission_preflight(text,uuid,text)','portal_browser_owner',true,false),
        ('public.portal_browser_revoke(text,text)','portal_browser_owner',true,false),
        ('public.portal_browser_envelope_read(text)','portal_browser_envelope_owner',false,true),
        ('public.portal_browser_envelope_write(text,text)','portal_browser_envelope_owner',false,true),
        ('public.portal_browser_envelope_destroy(text)','portal_browser_envelope_owner',false,true),
        ('public.portal_browser_envelope_gc()','portal_browser_envelope_owner',false,true)
      ) AS expected(signature,owner_name,identity_execute,envelope_execute)
      LEFT JOIN pg_proc p ON p.oid=to_regprocedure(expected.signature)
     WHERE p.oid IS NULL
        OR NOT p.prosecdef
        OR pg_get_userbyid(p.proowner) IS DISTINCT FROM expected.owner_name
        OR NOT COALESCE('row_security=on'=ANY(p.proconfig),false)
        OR NOT EXISTS (
           SELECT 1 FROM unnest(p.proconfig) AS settings(item)
           WHERE regexp_replace(settings.item,'[[:space:]]','','g')
             = 'search_path=pg_catalog,pg_temp'
        )
        OR has_function_privilege('portal_browser_runtime',p.oid,'EXECUTE')
             IS DISTINCT FROM expected.identity_execute
        OR has_function_privilege('portal_browser_envelope_runtime',p.oid,'EXECUTE')
             IS DISTINCT FROM expected.envelope_execute;
    IF mismatches <> 0 THEN
        RAISE EXCEPTION 'Restored identity/envelope functions violate owner, hardening or EXECUTE matrix';
    END IF;

    SELECT count(*) INTO mismatches FROM public.migrations
     WHERE migration NOT IN (
         '2026_11_09_000002_create_portal_identity_foundation',
         '2026_11_09_000003_add_mfa_replay_counter_to_portal_identities',
         '2026_11_10_000004_add_org_revision_to_portal_sessions',
         '2026_11_10_000005_create_portal_auth_throttle_buckets',
         '2026_11_10_000008_add_session_purpose_to_portal_auth_sessions',
         '2026_11_11_000012_fence_portal_membership_revision',
         '2026_11_11_000013_fence_portal_organization_revision',
         '2026_11_11_000014_guard_portal_session_issuance',
         '2026_11_12_000016_fence_portal_identity_revision',
         '2026_11_12_000017_create_portal_service_resource_claims'
     );
    IF mismatches <> 0 OR (SELECT count(*) FROM public.migrations) <> 10 THEN
        RAISE EXCEPTION 'Restored migrations do not match reviewed allowlist';
    END IF;
END
$restore_contract$;
