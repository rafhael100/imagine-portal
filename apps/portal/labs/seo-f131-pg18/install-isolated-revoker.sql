-- F131: CI-only proof of a narrow SECURITY DEFINER revocation boundary.
-- NEVER execute against production: this file creates PostgreSQL roles.
-- The first database guard is authoritative for the disposable runner.
DO $f131_guard$
BEGIN
    IF current_database() <> 'portal_gsc_f108_ci' THEN
        RAISE EXCEPTION 'F131: only the synthetic PG18 CI database is allowed';
    END IF;
END;
$f131_guard$;

CREATE ROLE portal_seo_f131_function_owner
    NOLOGIN NOSUPERUSER NOBYPASSRLS NOINHERIT;
CREATE ROLE portal_seo_f131_operator
    NOLOGIN NOSUPERUSER NOBYPASSRLS NOINHERIT;

GRANT USAGE ON SCHEMA public TO portal_seo_f131_function_owner;
GRANT SELECT,UPDATE ON public.portal_seo_build_signing_keys
    TO portal_seo_f131_function_owner;
GRANT SELECT,INSERT ON public.portal_seo_build_signer_events
    TO portal_seo_f131_function_owner;

-- The function gets only the permissions of a dedicated NOLOGIN owner;
-- the calling operator role never receives table UPDATE or event INSERT.
-- The current app.tenant_ref GUC is still just synthetic caller context,
-- NOT a real authenticated/cryptographically bound tenant identity.
CREATE FUNCTION public.portal_seo_f131_revoke_signer(
    p_tenant text,
    p_signer text,
    p_fingerprint text,
    p_actor text,
    p_revoked_at bigint,
    p_event_digest text
) RETURNS boolean
LANGUAGE plpgsql SECURITY DEFINER
SET search_path=pg_catalog,public
AS $f131$
DECLARE
    v_scope text;
BEGIN
    IF current_database()<>'portal_gsc_f108_ci' THEN
        RETURN false;
    END IF;

    v_scope:=current_setting('app.tenant_ref',true);
    IF v_scope IS NULL OR v_scope IS DISTINCT FROM p_tenant OR
       p_tenant !~ '^[a-z0-9][a-z0-9_-]{2,63}$' OR
       p_signer !~ '^[a-z0-9][a-z0-9_-]{1,79}$' OR
       p_actor !~ '^[a-z0-9][a-z0-9_-]{2,63}$' OR
       p_fingerprint !~ '^[a-f0-9]{64}$' OR
       p_event_digest !~ '^[a-f0-9]{64}$' OR
       p_revoked_at<1700000000 OR p_revoked_at>4102444800
    THEN
        RETURN false;
    END IF;

    -- Exact same signer lock protocol as the PHP verifier (F128), held
    -- throughout key UPDATE and mandatory audit INSERT.
    PERFORM pg_catalog.pg_advisory_xact_lock(
        pg_catalog.hashtext('imagine.seo.signing.v1:'||p_tenant),
        pg_catalog.hashtext(p_signer)
    );

    UPDATE public.portal_seo_build_signing_keys
       SET key_status='revoked',revoked_at=p_revoked_at
     WHERE tenant_ref=p_tenant
       AND signer_ref=p_signer
       AND public_key_fingerprint=p_fingerprint
       AND key_status='active'
       AND revoked_at IS NULL
       AND not_before<=p_revoked_at;

    IF NOT FOUND THEN
        RETURN false;
    END IF;

    -- F129 and F130 trigger guards require this event before COMMIT.
    -- INSERT failure aborts the entire caller transaction (no partial key
    -- update), checked by F131 adversarial tests.
    INSERT INTO public.portal_seo_build_signer_events
        (tenant_ref,event_digest,signer_ref,public_key_fingerprint,
         actor_ref,event_code,occurred_at)
    VALUES
        (p_tenant,p_event_digest,p_signer,p_fingerprint,
         p_actor,'signer_key_revoked',p_revoked_at);

    RETURN true;
END;
$f131$;

ALTER FUNCTION public.portal_seo_f131_revoke_signer(
    text,text,text,text,bigint,text
) OWNER TO portal_seo_f131_function_owner;

-- PostgreSQL normally grants EXECUTE on newly created functions to PUBLIC.
-- Revoke it explicitly and expose only the narrow operator capability.
REVOKE ALL ON FUNCTION public.portal_seo_f131_revoke_signer(
    text,text,text,text,bigint,text
) FROM PUBLIC;

GRANT EXECUTE ON FUNCTION public.portal_seo_f131_revoke_signer(
    text,text,text,text,bigint,text
) TO portal_seo_f131_operator;
