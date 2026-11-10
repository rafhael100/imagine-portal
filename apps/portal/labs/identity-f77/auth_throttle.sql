-- PORTAL-F77: transactional PostgreSQL rate-limit proof, not a login service.
-- The trusted server must compute scope_hash using HMAC with a server-owned
-- pepper and a documented scope (account+IP / IP / recovery). No PII is stored.
-- NOT SAFE as a public SQL RPC; a compromised role could exhaust buckets.
BEGIN;

CREATE FUNCTION public.portal_f77_claim_auth_attempt(
    p_scope_hash text, p_action text
) RETURNS boolean
LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path = pg_catalog, pg_temp
AS $f77$
DECLARE
    v_allowed boolean := FALSE;
BEGIN
    IF p_scope_hash IS NULL
       OR p_scope_hash !~ '^[a-f0-9]{64}$'
       OR p_action NOT IN ('login','totp','recovery') THEN
        RETURN FALSE;
    END IF;

    -- One atomic INSERT/UPSERT per scope/action; PostgreSQL row locks
    -- serialize competing attempts, even from different application nodes.
    -- The sixth attempt in a 15-minute window is rejected.
    INSERT INTO public.portal_auth_attempt_buckets AS b
        (scope_hash,action_code,attempts,window_started_at,last_seen_at)
    VALUES
        (p_scope_hash,p_action,1,statement_timestamp(),statement_timestamp())
    ON CONFLICT (scope_hash,action_code)
    DO UPDATE SET
        attempts=CASE
            WHEN b.window_started_at <= statement_timestamp() - interval '15 minutes'
                THEN 1
            ELSE LEAST(b.attempts + 1, 6)
        END,
        window_started_at=CASE
            WHEN b.window_started_at <= statement_timestamp() - interval '15 minutes'
                THEN statement_timestamp()
            ELSE b.window_started_at
        END,
        last_seen_at=statement_timestamp()
    RETURNING attempts <= 5 INTO v_allowed;

    RETURN COALESCE(v_allowed,FALSE);
END;
$f77$;

REVOKE ALL ON FUNCTION public.portal_f77_claim_auth_attempt(text,text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.portal_f77_claim_auth_attempt(text,text)
    TO portal_f77_throttler;
COMMIT;
