-- Candidate encrypted browser envelope store; no identity-table privileges.
BEGIN;
CREATE ROLE portal_browser_envelope_owner NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
CREATE ROLE portal_browser_envelope_runtime NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
GRANT USAGE,CREATE ON SCHEMA public TO portal_browser_envelope_owner;
GRANT USAGE ON SCHEMA public TO portal_browser_envelope_runtime;
CREATE TABLE public.portal_browser_envelopes(
 id_sha256 char(64) PRIMARY KEY CHECK(id_sha256 ~ '^[a-f0-9]{64}$'),
 payload text NOT NULL CHECK(octet_length(payload) BETWEEN 1 AND 65536),
 expires_at timestamptz(6) NOT NULL
);
CREATE INDEX portal_browser_envelopes_expiry ON public.portal_browser_envelopes(expires_at);
ALTER TABLE public.portal_browser_envelopes ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.portal_browser_envelopes FORCE ROW LEVEL SECURITY;
REVOKE ALL ON public.portal_browser_envelopes FROM PUBLIC;
GRANT SELECT,INSERT,UPDATE,DELETE ON public.portal_browser_envelopes TO portal_browser_envelope_owner;
CREATE POLICY portal_browser_envelope_owner_policy ON public.portal_browser_envelopes TO portal_browser_envelope_owner
 USING(true) WITH CHECK(true);

CREATE FUNCTION public.portal_browser_envelope_read(p_id text)
RETURNS text LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path=pg_catalog,pg_temp SET row_security=on AS $fn$
DECLARE v_payload text;
BEGIN
 IF p_id IS NULL OR p_id !~ '^[a-f0-9]{64}$' THEN RAISE EXCEPTION 'Invalid envelope digest'; END IF;
 PERFORM pg_advisory_xact_lock(hashtextextended('imagine:portal:browser:'||p_id,0));
 SELECT payload INTO v_payload FROM public.portal_browser_envelopes
 WHERE id_sha256=p_id AND expires_at>clock_timestamp();
 RETURN COALESCE(v_payload,'');
END;
$fn$;
CREATE FUNCTION public.portal_browser_envelope_write(p_id text,p_payload text)
RETURNS boolean LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path=pg_catalog,pg_temp SET row_security=on AS $fn$
BEGIN
 IF p_id IS NULL OR p_id !~ '^[a-f0-9]{64}$'
 OR p_payload IS NULL OR octet_length(p_payload) NOT BETWEEN 1 AND 65536 THEN RETURN false; END IF;
 PERFORM pg_advisory_xact_lock(hashtextextended('imagine:portal:browser:'||p_id,0));
 INSERT INTO public.portal_browser_envelopes(id_sha256,payload,expires_at)
 VALUES(p_id,p_payload,clock_timestamp()+interval '2 hours')
 ON CONFLICT(id_sha256) DO UPDATE SET payload=EXCLUDED.payload,expires_at=EXCLUDED.expires_at;
 RETURN true;
END;
$fn$;
CREATE FUNCTION public.portal_browser_envelope_destroy(p_id text)
RETURNS boolean LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path=pg_catalog,pg_temp SET row_security=on AS $fn$
BEGIN
 IF p_id IS NULL OR p_id !~ '^[a-f0-9]{64}$' THEN RETURN false; END IF;
 PERFORM pg_advisory_xact_lock(hashtextextended('imagine:portal:browser:'||p_id,0));
 DELETE FROM public.portal_browser_envelopes WHERE id_sha256=p_id;
 RETURN true;
END;
$fn$;
CREATE FUNCTION public.portal_browser_envelope_gc()
RETURNS bigint LANGUAGE sql VOLATILE SECURITY DEFINER
SET search_path=pg_catalog,pg_temp SET row_security=on AS $fn$
 WITH victims AS (
 SELECT id_sha256 FROM public.portal_browser_envelopes WHERE expires_at<=clock_timestamp()
 ORDER BY expires_at LIMIT 1000 FOR UPDATE SKIP LOCKED
 ), deleted AS (
 DELETE FROM public.portal_browser_envelopes e USING victims v WHERE e.id_sha256=v.id_sha256 RETURNING e.id_sha256
 ) SELECT count(*) FROM deleted;
$fn$;
DO $roles$
DECLARE signature text;
BEGIN
 FOREACH signature IN ARRAY ARRAY[
 'public.portal_browser_envelope_read(text)',
 'public.portal_browser_envelope_write(text,text)',
 'public.portal_browser_envelope_destroy(text)',
 'public.portal_browser_envelope_gc()']
 LOOP
 EXECUTE format('ALTER FUNCTION %s OWNER TO portal_browser_envelope_owner',signature);
 EXECUTE format('REVOKE ALL ON FUNCTION %s FROM PUBLIC',signature);
 EXECUTE format('GRANT EXECUTE ON FUNCTION %s TO portal_browser_envelope_runtime',signature);
 END LOOP;
END;
$roles$;
REVOKE CREATE ON SCHEMA public FROM portal_browser_envelope_owner;
COMMIT;
