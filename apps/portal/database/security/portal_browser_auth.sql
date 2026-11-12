-- Candidate security installation; only exercised in a disposable PG18 database.
-- Not a migration/deploy command. Runtime remains NOLOGIN until provisioning.
BEGIN;
SET LOCAL search_path=pg_catalog,public;
CREATE EXTENSION IF NOT EXISTS pgcrypto WITH SCHEMA public;
CREATE ROLE portal_browser_owner NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
CREATE ROLE portal_browser_runtime NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
GRANT USAGE,CREATE ON SCHEMA public TO portal_browser_owner;
GRANT USAGE ON SCHEMA public TO portal_browser_runtime;
CREATE SCHEMA portal_browser_private;
REVOKE ALL ON SCHEMA portal_browser_private FROM PUBLIC;
GRANT USAGE ON SCHEMA portal_browser_private TO portal_browser_owner;
CREATE TABLE portal_browser_private.proof_key (
  singleton boolean PRIMARY KEY DEFAULT true CHECK(singleton),
  secret text NOT NULL CHECK(secret ~ '^[a-f0-9]{64}$')
);
ALTER TABLE portal_browser_private.proof_key ENABLE ROW LEVEL SECURITY;
ALTER TABLE portal_browser_private.proof_key FORCE ROW LEVEL SECURITY;
REVOKE ALL ON TABLE portal_browser_private.proof_key FROM PUBLIC;
GRANT SELECT ON portal_browser_private.proof_key TO portal_browser_owner;
CREATE POLICY portal_browser_key_read ON portal_browser_private.proof_key
 FOR SELECT TO portal_browser_owner USING(true);

GRANT SELECT(id,email_normalized,status,password_hash,mfa_secret_ciphertext,
 email_verified_at,mfa_confirmed_at,mfa_last_used_counter,security_revision)
 ON public.portal_identities TO portal_browser_owner;
GRANT UPDATE(mfa_last_used_counter,updated_at) ON public.portal_identities TO portal_browser_owner;
CREATE POLICY portal_browser_identity_read ON public.portal_identities FOR SELECT TO portal_browser_owner USING(true);
CREATE POLICY portal_browser_identity_counter ON public.portal_identities FOR UPDATE TO portal_browser_owner
 USING(status='active') WITH CHECK(status='active');
GRANT SELECT(id,status,security_revision) ON public.portal_organizations TO portal_browser_owner;
-- Row-lock privilege on immutable identifiers; runtime receives no table grants.
GRANT UPDATE(id) ON public.portal_organizations TO portal_browser_owner;
GRANT UPDATE(id) ON public.portal_memberships TO portal_browser_owner;
CREATE POLICY portal_browser_org_read ON public.portal_organizations FOR SELECT TO portal_browser_owner USING(true);
GRANT SELECT(identity_id,organization_id,status,membership_revision) ON public.portal_memberships TO portal_browser_owner;
CREATE POLICY portal_browser_member_read ON public.portal_memberships FOR SELECT TO portal_browser_owner USING(true);
CREATE POLICY portal_browser_member_lock ON public.portal_memberships FOR UPDATE TO portal_browser_owner USING(true) WITH CHECK(true);
CREATE POLICY portal_browser_org_lock ON public.portal_organizations FOR UPDATE TO portal_browser_owner USING(true) WITH CHECK(true);
GRANT SELECT(id,session_sha256,identity_id,organization_id,status,session_purpose,
 identity_revision,membership_revision,organization_revision,issued_at,expires_at,mfa_verified_at,revoked_at)
 ON public.portal_auth_sessions TO portal_browser_owner;
GRANT INSERT(id,session_sha256,identity_id,organization_id,status,session_purpose,
 identity_revision,membership_revision,organization_revision,issued_at,expires_at,
 mfa_verified_at,created_at,updated_at)
 ON public.portal_auth_sessions TO portal_browser_owner;
GRANT UPDATE(status,revoked_at,updated_at) ON public.portal_auth_sessions TO portal_browser_owner;
CREATE POLICY portal_browser_session_read ON public.portal_auth_sessions FOR SELECT TO portal_browser_owner USING(true);
CREATE POLICY portal_browser_session_insert ON public.portal_auth_sessions FOR INSERT TO portal_browser_owner
 WITH CHECK(session_purpose='business');
CREATE POLICY portal_browser_session_revoke ON public.portal_auth_sessions FOR UPDATE TO portal_browser_owner
 USING(true) WITH CHECK(status='revoked' AND revoked_at IS NOT NULL);
GRANT SELECT,INSERT,UPDATE ON public.portal_auth_attempt_buckets TO portal_browser_owner;
CREATE POLICY portal_browser_attempts ON public.portal_auth_attempt_buckets TO portal_browser_owner USING(true) WITH CHECK(true);

CREATE FUNCTION public.portal_browser_proof_ok(p_payload text,p_proof text)
RETURNS boolean LANGUAGE sql STABLE SECURITY DEFINER
SET search_path=pg_catalog,pg_temp SET row_security=on AS $fn$
 SELECT COALESCE(p_proof ~ '^[a-f0-9]{64}$' AND EXISTS(
  SELECT 1 FROM portal_browser_private.proof_key k
  WHERE public.digest(encode(public.hmac(p_payload,k.secret,'sha256'),'hex'),'sha256')
      = public.digest(p_proof,'sha256')
 ),false);
$fn$;

CREATE FUNCTION public.portal_browser_claim_attempt(p_scope text,p_action text)
RETURNS boolean LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path=pg_catalog,pg_temp SET row_security=on AS $fn$
DECLARE v_allowed boolean;
BEGIN
 IF p_scope IS NULL OR p_scope !~ '^[a-f0-9]{64}$' OR p_action NOT IN ('login','totp') THEN RETURN false; END IF;
 INSERT INTO public.portal_auth_attempt_buckets AS b(scope_hash,action_code,attempts,window_started_at,last_seen_at)
 VALUES(p_scope,p_action,1,clock_timestamp(),clock_timestamp())
 ON CONFLICT(scope_hash,action_code) DO UPDATE SET
 attempts=CASE WHEN b.window_started_at<=clock_timestamp()-interval '15 minutes' THEN 1 ELSE LEAST(b.attempts+1,6) END,
 window_started_at=CASE WHEN b.window_started_at<=clock_timestamp()-interval '15 minutes' THEN clock_timestamp() ELSE b.window_started_at END,
 last_seen_at=clock_timestamp()
 RETURNING attempts<=5 INTO v_allowed;
 RETURN COALESCE(v_allowed,false);
END;
$fn$;

CREATE FUNCTION public.portal_browser_material(p_email text,p_org uuid)
RETURNS TABLE(identity_id uuid,password_hash text,mfa_ciphertext text,last_counter bigint,
 credential_fingerprint text,secret_fingerprint text,epoch bigint)
LANGUAGE sql VOLATILE SECURITY DEFINER
SET search_path=pg_catalog,pg_temp SET row_security=on AS $fn$
 SELECT i.id,i.password_hash,i.mfa_secret_ciphertext,i.mfa_last_used_counter,
 encode(public.digest(i.password_hash,'sha256'),'hex'),
 encode(public.digest(i.mfa_secret_ciphertext,'sha256'),'hex'),
 floor(extract(epoch FROM clock_timestamp()))::bigint
 FROM public.portal_identities i
 JOIN public.portal_memberships m ON m.identity_id=i.id
 JOIN public.portal_organizations o ON o.id=m.organization_id
 WHERE i.email_normalized=p_email AND o.id=p_org
 AND i.status='active' AND m.status='active' AND o.status='active'
 AND i.email_verified_at<=clock_timestamp() AND i.mfa_confirmed_at<=clock_timestamp()
 AND i.password_hash IS NOT NULL AND i.mfa_secret_ciphertext LIKE 'mfa-sb1:%'
 AND i.security_revision>0 AND m.membership_revision>0 AND o.security_revision>0;
$fn$;

CREATE FUNCTION public.portal_browser_begin(p_email text,p_org uuid,p_digest text,p_credential text,p_proof text)
RETURNS boolean LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path=pg_catalog,pg_temp SET row_security=on AS $fn$
DECLARE r record; v_now timestamptz;
BEGIN
 IF p_digest IS NULL OR p_digest !~ '^[a-f0-9]{64}$'
 OR NOT public.portal_browser_proof_ok('portal-browser-v1|password|'||p_email||'|'||p_org::text||'|'||p_digest||'|'||p_credential,p_proof)
 THEN RETURN false; END IF;
 SELECT i.id,i.security_revision AS ir,m.membership_revision AS mr,o.security_revision AS orv INTO r
 FROM public.portal_identities i JOIN public.portal_memberships m ON m.identity_id=i.id
 JOIN public.portal_organizations o ON o.id=m.organization_id
 WHERE i.email_normalized=p_email AND o.id=p_org
 AND i.status='active' AND m.status='active' AND o.status='active'
 AND i.email_verified_at<=clock_timestamp() AND i.mfa_confirmed_at<=clock_timestamp()
 AND encode(public.digest(i.password_hash,'sha256'),'hex')=p_credential
 AND i.mfa_secret_ciphertext LIKE 'mfa-sb1:%'
 FOR UPDATE OF i,m,o;
 IF NOT FOUND THEN RETURN false; END IF;
 v_now:=clock_timestamp();
 INSERT INTO public.portal_auth_sessions(id,session_sha256,identity_id,organization_id,status,session_purpose,
 identity_revision,membership_revision,organization_revision,issued_at,expires_at,created_at,updated_at)
 VALUES(gen_random_uuid(),p_digest,r.id,p_org,'pending_mfa','business',r.ir,r.mr,r.orv,
 v_now,v_now+interval '5 minutes',v_now,v_now);
 RETURN true;
END;
$fn$;

CREATE FUNCTION public.portal_browser_pending(p_digest text)
RETURNS TABLE(identity_id uuid,mfa_ciphertext text,last_counter bigint,secret_fingerprint text,epoch bigint)
LANGUAGE sql VOLATILE SECURITY DEFINER
SET search_path=pg_catalog,pg_temp SET row_security=on AS $fn$
 SELECT i.id,i.mfa_secret_ciphertext,i.mfa_last_used_counter,
 encode(public.digest(i.mfa_secret_ciphertext,'sha256'),'hex'),
 floor(extract(epoch FROM clock_timestamp()))::bigint
 FROM public.portal_auth_sessions s
 JOIN public.portal_identities i ON i.id=s.identity_id
 JOIN public.portal_memberships m ON m.identity_id=i.id AND m.organization_id=s.organization_id
 JOIN public.portal_organizations o ON o.id=s.organization_id
 WHERE s.session_sha256=p_digest AND p_digest ~ '^[a-f0-9]{64}$'
 AND s.status='pending_mfa' AND s.session_purpose='business' AND s.revoked_at IS NULL
 AND s.issued_at<=clock_timestamp() AND s.expires_at>clock_timestamp()
 AND s.expires_at<=s.issued_at+interval '5 minutes'
 AND i.status='active' AND m.status='active' AND o.status='active'
 AND i.email_verified_at<=clock_timestamp() AND i.mfa_confirmed_at<=clock_timestamp()
 AND s.identity_revision=i.security_revision AND s.membership_revision=m.membership_revision
 AND s.organization_revision=o.security_revision;
$fn$;

CREATE FUNCTION public.portal_browser_complete(p_pending text,p_active text,p_counter bigint,p_secret text,p_proof text)
RETURNS boolean LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path=pg_catalog,pg_temp SET row_security=on AS $fn$
DECLARE r record; v_now timestamptz; v_epoch bigint;
BEGIN
 IF p_pending IS NULL OR p_active IS NULL OR p_pending=p_active
 OR p_pending !~ '^[a-f0-9]{64}$' OR p_active !~ '^[a-f0-9]{64}$'
 OR NOT public.portal_browser_proof_ok('portal-browser-v1|mfa|'||p_pending||'|'||p_active||'|'||p_counter::text||'|'||p_secret,p_proof)
 THEN RETURN false; END IF;
 SELECT s.id,s.identity_id,s.organization_id,s.identity_revision,s.membership_revision,s.organization_revision,
 i.mfa_last_used_counter INTO r
 FROM public.portal_auth_sessions s JOIN public.portal_identities i ON i.id=s.identity_id
 JOIN public.portal_memberships m ON m.identity_id=i.id AND m.organization_id=s.organization_id
 JOIN public.portal_organizations o ON o.id=s.organization_id
 WHERE s.session_sha256=p_pending AND s.status='pending_mfa' AND s.session_purpose='business'
 AND s.revoked_at IS NULL AND s.issued_at<=clock_timestamp() AND s.expires_at>clock_timestamp()
 AND s.expires_at<=s.issued_at+interval '5 minutes'
 AND i.status='active' AND m.status='active' AND o.status='active'
 AND i.email_verified_at<=clock_timestamp() AND i.mfa_confirmed_at<=clock_timestamp()
 AND encode(public.digest(i.mfa_secret_ciphertext,'sha256'),'hex')=p_secret
 AND s.identity_revision=i.security_revision AND s.membership_revision=m.membership_revision
 AND s.organization_revision=o.security_revision
 FOR UPDATE OF i,m,o,s;
 IF NOT FOUND THEN RETURN false; END IF;
 v_now:=clock_timestamp(); v_epoch:=floor(extract(epoch FROM v_now))::bigint;
 IF p_counter IS NULL OR p_counter<=r.mfa_last_used_counter
 OR p_counter<v_epoch/30-1 OR p_counter>v_epoch/30+1 THEN RETURN false; END IF;
 UPDATE public.portal_identities SET mfa_last_used_counter=p_counter,updated_at=v_now WHERE id=r.identity_id;
 UPDATE public.portal_auth_sessions SET status='revoked',revoked_at=v_now,updated_at=v_now WHERE id=r.id;
 INSERT INTO public.portal_auth_sessions(id,session_sha256,identity_id,organization_id,status,session_purpose,
 identity_revision,membership_revision,organization_revision,issued_at,expires_at,mfa_verified_at,created_at,updated_at)
 VALUES(gen_random_uuid(),p_active,r.identity_id,r.organization_id,'active','business',
 r.identity_revision,r.membership_revision,r.organization_revision,v_now,v_now+interval '2 hours',v_now,v_now,v_now);
 RETURN true;
END;
$fn$;

CREATE FUNCTION public.portal_browser_active(p_digest text)
RETURNS boolean LANGUAGE sql VOLATILE SECURITY DEFINER
SET search_path=pg_catalog,pg_temp SET row_security=on AS $fn$
 SELECT EXISTS(
 SELECT 1 FROM public.portal_auth_sessions s
 JOIN public.portal_identities i ON i.id=s.identity_id
 JOIN public.portal_memberships m ON m.identity_id=i.id AND m.organization_id=s.organization_id
 JOIN public.portal_organizations o ON o.id=s.organization_id
 WHERE s.session_sha256=p_digest AND p_digest ~ '^[a-f0-9]{64}$'
 AND s.status='active' AND s.session_purpose='business' AND s.revoked_at IS NULL
 AND s.issued_at<=clock_timestamp() AND s.expires_at>clock_timestamp()
 AND s.expires_at<=s.issued_at+interval '2 hours'
 AND s.mfa_verified_at>=s.issued_at AND s.mfa_verified_at<=clock_timestamp()
 AND i.status='active' AND m.status='active' AND o.status='active'
 AND i.email_verified_at<=clock_timestamp() AND i.mfa_confirmed_at<=clock_timestamp()
 AND s.identity_revision=i.security_revision AND s.membership_revision=m.membership_revision
 AND s.organization_revision=o.security_revision);
$fn$;

CREATE FUNCTION public.portal_browser_revoke(p_digest text,p_proof text)
RETURNS boolean LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path=pg_catalog,pg_temp SET row_security=on AS $fn$
BEGIN
 IF p_digest IS NULL OR p_digest !~ '^[a-f0-9]{64}$'
 OR NOT public.portal_browser_proof_ok('portal-browser-v1|revoke|'||p_digest,p_proof) THEN RETURN false; END IF;
 UPDATE public.portal_auth_sessions SET status='revoked',revoked_at=clock_timestamp(),updated_at=clock_timestamp()
 WHERE session_sha256=p_digest AND status IN ('pending_mfa','active') AND revoked_at IS NULL;
 RETURN true;
END;
$fn$;

DO $roles$
DECLARE f record;
BEGIN
 FOR f IN SELECT p.oid::regprocedure AS signature,p.proname FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace
 WHERE n.nspname='public' AND p.proname IN (
 'portal_browser_proof_ok','portal_browser_claim_attempt','portal_browser_material','portal_browser_begin',
 'portal_browser_pending','portal_browser_complete','portal_browser_active','portal_browser_revoke')
 LOOP
 EXECUTE format('ALTER FUNCTION %s OWNER TO portal_browser_owner',f.signature);
 EXECUTE format('REVOKE ALL ON FUNCTION %s FROM PUBLIC',f.signature);
 IF f.proname<>'portal_browser_proof_ok' THEN
 EXECUTE format('GRANT EXECUTE ON FUNCTION %s TO portal_browser_runtime',f.signature);
 END IF;
 END LOOP;
END;
$roles$;
REVOKE CREATE ON SCHEMA public FROM portal_browser_owner;
COMMIT;
