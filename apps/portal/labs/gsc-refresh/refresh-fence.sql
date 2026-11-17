-- Synthetic early renewal only. Applied AFTER post-response-fence.sql in PG18.
-- No production migration, new business permission, vault codec or retention rule.
\set ON_ERROR_STOP on
BEGIN;
DO $guard$
BEGIN
 IF current_database()<>'portal_gsc_f115_lab'
 OR current_setting('server_version_num')::integer/10000<>18
 THEN RAISE EXCEPTION 'Disposable PG18 GSC laboratory required'; END IF;
END;
$guard$;
CREATE ROLE portal_gsc_refresh_owner NOLOGIN NOSUPERUSER NOCREATEDB
 NOCREATEROLE NOINHERIT NOBYPASSRLS NOREPLICATION;
GRANT USAGE,CREATE ON SCHEMA public TO portal_gsc_refresh_owner;
GRANT EXECUTE ON FUNCTION public.portal_gsc_f114_connect_principal(text,uuid)
 TO portal_gsc_refresh_owner;
GRANT SELECT,DELETE,UPDATE(property_fingerprint)
 ON public.portal_gsc_oauth_token_candidates,public.portal_gsc_property_grants
 TO portal_gsc_refresh_owner;

-- Only metadata. No token, ciphertext, key, OAuth code, verifier or HTTP body.
-- A failed/ambiguous attempt remains claimed: no automatic retry for that version.
CREATE TABLE public.portal_gsc_refresh_lab_claims (
 claim_id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
 tenant_ref text NOT NULL,actor_ref text NOT NULL,property_fingerprint text NOT NULL,
 session_digest text NOT NULL,organization_uuid uuid NOT NULL,
 membership_revision bigint NOT NULL,
 predecessor_hash text NOT NULL,predecessor_issued bigint NOT NULL,
 predecessor_expires bigint NOT NULL,predecessor_verified bigint NOT NULL,
 claimed_at bigint NOT NULL,claim_expires bigint NOT NULL,
 status text NOT NULL DEFAULT 'claimed' CHECK(status IN('claimed','writing','completed')),
 write_txid bigint,successor_hash text,successor_issued bigint,successor_expires bigint,
 CONSTRAINT portal_gsc_refresh_version_once UNIQUE(tenant_ref,actor_ref,property_fingerprint,predecessor_hash),
 CHECK(property_fingerprint ~ '^[a-f0-9]{64}$' AND predecessor_hash ~ '^[a-f0-9]{64}$'),
 CHECK(session_digest ~ '^[a-f0-9]{64}$' AND membership_revision>0),
 CHECK(claim_expires=claimed_at+30 AND predecessor_expires>claimed_at)
);
ALTER TABLE public.portal_gsc_refresh_lab_claims OWNER TO portal_gsc_refresh_owner;
ALTER TABLE public.portal_gsc_refresh_lab_claims ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.portal_gsc_refresh_lab_claims FORCE ROW LEVEL SECURITY;
CREATE POLICY portal_refresh_claim_owner ON public.portal_gsc_refresh_lab_claims
 TO portal_gsc_refresh_owner USING(true) WITH CHECK(true);
REVOKE ALL ON public.portal_gsc_refresh_lab_claims FROM PUBLIC;
-- Key re-encryption changes the ciphertext hash without changing OAuth
-- material. An unfinished claim therefore excludes ALL versions of a tuple.
CREATE UNIQUE INDEX portal_gsc_refresh_one_unfinished
 ON public.portal_gsc_refresh_lab_claims(tenant_ref,actor_ref,property_fingerprint)
 WHERE status<>'completed';

CREATE FUNCTION public.portal_gsc_refresh_claim(
 p_session text,p_org uuid,p_property text
) RETURNS TABLE(claim_id uuid,tenant_ref text,actor_ref text,predecessor_hash text)
LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path=pg_catalog,pg_temp SET row_security=on
AS $claim$
DECLARE p record;t public.portal_gsc_oauth_token_candidates%ROWTYPE;
 g public.portal_gsc_property_grants%ROWTYPE;
 v_tenant text;v_actor text;v_hash text;v_id uuid;v_now bigint;
BEGIN
 IF current_database()<>'portal_gsc_f115_lab' OR session_user<>'portal_gsc_f114_runtime'
 OR p_session IS NULL OR p_session !~ '^[a-f0-9]{64}$'
 OR p_property IS NULL OR p_property !~ '^[a-f0-9]{64}$' THEN RETURN; END IF;
 SELECT * INTO p FROM public.portal_gsc_f114_connect_principal(p_session,p_org);
 IF NOT FOUND THEN RETURN; END IF;
 v_tenant:='porg-'||replace(p.organization_uuid::text,'-','');
 v_actor:='pusr-'||replace(p.identity_uuid::text,'-','');
 PERFORM set_config('app.tenant_ref',v_tenant,true),set_config('app.actor_ref',v_actor,true);
 PERFORM pg_advisory_xact_lock(hashtextextended(v_tenant||'|'||v_actor||'|'||p_property,0));
 SELECT * INTO t FROM public.portal_gsc_oauth_token_candidates x
 WHERE x.tenant_ref=v_tenant AND x.actor_ref=v_actor AND x.property_fingerprint=p_property
 FOR UPDATE;
 IF NOT FOUND THEN RETURN; END IF;
 SELECT * INTO g FROM public.portal_gsc_property_grants x
 WHERE x.tenant_ref=v_tenant AND x.actor_ref=v_actor AND x.property_fingerprint=p_property
 FOR UPDATE;
 IF NOT FOUND THEN RETURN; END IF;
 v_now:=floor(extract(epoch FROM clock_timestamp()))::bigint;
 IF t.revoked_at IS NOT NULL OR g.revoked_at IS NOT NULL OR g.grant_status<>'active'
 OR t.issued_at>v_now OR t.expires_at<=v_now OR g.verified_at>v_now
 OR g.expires_at<>t.expires_at OR g.expires_at<=v_now
 OR t.scope<>'https://www.googleapis.com/auth/webmasters.readonly' OR g.scope<>t.scope
 THEN RETURN; END IF;
 v_hash:=encode(sha256(convert_to(t.sealed_tokens,'UTF8')),'hex');
 INSERT INTO public.portal_gsc_refresh_lab_claims AS c
 (tenant_ref,actor_ref,property_fingerprint,session_digest,organization_uuid,
  membership_revision,predecessor_hash,predecessor_issued,predecessor_expires,
  predecessor_verified,claimed_at,claim_expires)
 VALUES(v_tenant,v_actor,p_property,p_session,p_org,p.membership_revision,
  v_hash,t.issued_at,t.expires_at,g.verified_at,v_now,v_now+30)
 ON CONFLICT DO NOTHING RETURNING c.claim_id INTO v_id;
 IF v_id IS NULL THEN RETURN; END IF;
 claim_id:=v_id;tenant_ref:=v_tenant;actor_ref:=v_actor;predecessor_hash:=v_hash;
 RETURN NEXT;
END;
$claim$;

-- Reacquire the ORIGINAL principal and version after both HTTPS requests.
-- DELETE is only an internal stage of one transaction; the deferred trigger
-- below prevents that stage from ever committing by itself.
CREATE FUNCTION public.portal_gsc_refresh_begin_replace(
 p_claim uuid,p_session text,p_org uuid
) RETURNS TABLE(tenant_ref text,actor_ref text,property_fingerprint text)
LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path=pg_catalog,pg_temp SET row_security=on
AS $replace$
DECLARE p record;c public.portal_gsc_refresh_lab_claims%ROWTYPE;
 t public.portal_gsc_oauth_token_candidates%ROWTYPE;g public.portal_gsc_property_grants%ROWTYPE;
 v_tenant text;v_actor text;v_now bigint;
BEGIN
 IF current_database()<>'portal_gsc_f115_lab' OR session_user<>'portal_gsc_f114_runtime'
 THEN RETURN; END IF;
 SELECT * INTO p FROM public.portal_gsc_f114_connect_principal(p_session,p_org);
 IF NOT FOUND THEN RETURN; END IF;
 v_tenant:='porg-'||replace(p.organization_uuid::text,'-','');
 v_actor:='pusr-'||replace(p.identity_uuid::text,'-','');
 SELECT * INTO c FROM public.portal_gsc_refresh_lab_claims x WHERE x.claim_id=p_claim;
 IF NOT FOUND OR c.session_digest<>p_session OR c.organization_uuid<>p_org
 OR c.tenant_ref<>v_tenant OR c.actor_ref<>v_actor THEN RETURN; END IF;
 PERFORM set_config('app.tenant_ref',v_tenant,true),set_config('app.actor_ref',v_actor,true);
 PERFORM pg_advisory_xact_lock(hashtextextended(v_tenant||'|'||v_actor||'|'||c.property_fingerprint,0));
 SELECT * INTO c FROM public.portal_gsc_refresh_lab_claims x WHERE x.claim_id=p_claim FOR UPDATE;
 v_now:=floor(extract(epoch FROM clock_timestamp()))::bigint;
 IF c.status<>'claimed' OR c.claim_expires<=v_now OR c.claimed_at>v_now
 OR c.membership_revision<>p.membership_revision THEN RETURN; END IF;
 SELECT * INTO t FROM public.portal_gsc_oauth_token_candidates x
 WHERE x.tenant_ref=v_tenant AND x.actor_ref=v_actor AND x.property_fingerprint=c.property_fingerprint
 FOR UPDATE;
 IF NOT FOUND THEN RETURN; END IF;
 SELECT * INTO g FROM public.portal_gsc_property_grants x
 WHERE x.tenant_ref=v_tenant AND x.actor_ref=v_actor AND x.property_fingerprint=c.property_fingerprint
 FOR UPDATE;
 IF NOT FOUND THEN RETURN; END IF;
 IF t.revoked_at IS NOT NULL OR g.revoked_at IS NOT NULL OR g.grant_status<>'active'
 OR t.issued_at<>c.predecessor_issued OR t.expires_at<>c.predecessor_expires
 OR t.expires_at<=v_now OR g.expires_at<>c.predecessor_expires
 OR g.verified_at<>c.predecessor_verified OR g.verified_at>v_now
 OR encode(sha256(convert_to(t.sealed_tokens,'UTF8')),'hex')<>c.predecessor_hash
 OR t.scope<>'https://www.googleapis.com/auth/webmasters.readonly' OR g.scope<>t.scope
 THEN RETURN; END IF;
 UPDATE public.portal_gsc_refresh_lab_claims x SET status='writing',write_txid=txid_current()
 WHERE x.claim_id=p_claim;
 DELETE FROM public.portal_gsc_oauth_token_candidates x
 WHERE x.tenant_ref=v_tenant AND x.actor_ref=v_actor AND x.property_fingerprint=c.property_fingerprint;
 DELETE FROM public.portal_gsc_property_grants x
 WHERE x.tenant_ref=v_tenant AND x.actor_ref=v_actor AND x.property_fingerprint=c.property_fingerprint;
 PERFORM set_config('app.gsc_lab_refresh_claim',p_claim::text,true);
 tenant_ref:=v_tenant;actor_ref:=v_actor;property_fingerprint:=c.property_fingerprint;
 RETURN NEXT;
END;
$replace$;

CREATE FUNCTION public.portal_gsc_refresh_insert_scope(p_tenant text,p_actor text,p_property text)
RETURNS boolean LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path=pg_catalog,pg_temp SET row_security=on
AS $scope$
DECLARE v_claim text;v_session text;v_org text;p record;c public.portal_gsc_refresh_lab_claims%ROWTYPE;
BEGIN
 IF current_database()<>'portal_gsc_f115_lab' OR session_user<>'portal_gsc_f114_runtime'
 THEN RETURN false; END IF;
 v_claim:=current_setting('app.gsc_lab_refresh_claim',true);
 v_session:=current_setting('app.gsc_lab_session_digest',true);
 v_org:=current_setting('app.gsc_lab_organization',true);
 IF v_claim IS NULL OR v_claim !~ '^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$'
 OR v_org IS NULL OR v_org !~ '^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$'
 THEN RETURN false; END IF;
 SELECT * INTO c FROM public.portal_gsc_refresh_lab_claims x WHERE x.claim_id=v_claim::uuid;
 IF NOT FOUND OR c.status<>'writing' OR c.write_txid<>txid_current()
 OR c.tenant_ref<>p_tenant OR c.actor_ref<>p_actor OR c.property_fingerprint<>p_property
 OR c.session_digest IS DISTINCT FROM v_session OR c.organization_uuid<>v_org::uuid
 OR c.claim_expires<=floor(extract(epoch FROM clock_timestamp()))::bigint THEN RETURN false; END IF;
 SELECT * INTO p FROM public.portal_gsc_f114_connect_principal(v_session,c.organization_uuid);
 RETURN FOUND AND p.membership_revision=c.membership_revision
 AND 'porg-'||replace(p.organization_uuid::text,'-','')=p_tenant
 AND 'pusr-'||replace(p.identity_uuid::text,'-','')=p_actor;
END;
$scope$;

CREATE FUNCTION public.portal_gsc_refresh_complete(p_claim uuid,p_session text,p_org uuid)
RETURNS boolean LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path=pg_catalog,pg_temp SET row_security=on
AS $complete$
DECLARE c public.portal_gsc_refresh_lab_claims%ROWTYPE;
 t public.portal_gsc_oauth_token_candidates%ROWTYPE;g public.portal_gsc_property_grants%ROWTYPE;
BEGIN
 IF current_database()<>'portal_gsc_f115_lab' OR session_user<>'portal_gsc_f114_runtime'
 THEN RETURN false; END IF;
 SELECT * INTO c FROM public.portal_gsc_refresh_lab_claims x WHERE x.claim_id=p_claim FOR UPDATE;
 IF NOT FOUND OR c.session_digest IS DISTINCT FROM p_session OR c.organization_uuid IS DISTINCT FROM p_org
 OR NOT public.portal_gsc_refresh_insert_scope(c.tenant_ref,c.actor_ref,c.property_fingerprint)
 THEN RETURN false; END IF;
 SELECT * INTO t FROM public.portal_gsc_oauth_token_candidates x
 WHERE x.tenant_ref=c.tenant_ref AND x.actor_ref=c.actor_ref AND x.property_fingerprint=c.property_fingerprint;
 IF NOT FOUND THEN RETURN false; END IF;
 SELECT * INTO g FROM public.portal_gsc_property_grants x
 WHERE x.tenant_ref=c.tenant_ref AND x.actor_ref=c.actor_ref AND x.property_fingerprint=c.property_fingerprint;
 IF NOT FOUND THEN RETURN false; END IF;
 IF t.revoked_at IS NOT NULL OR g.revoked_at IS NOT NULL OR g.grant_status<>'active'
 OR t.issued_at<c.claimed_at OR t.issued_at>floor(extract(epoch FROM clock_timestamp()))::bigint
 OR t.expires_at<=floor(extract(epoch FROM clock_timestamp()))::bigint
 OR g.verified_at<>t.issued_at OR g.expires_at<>t.expires_at
 OR t.scope<>'https://www.googleapis.com/auth/webmasters.readonly' OR g.scope<>t.scope
 OR encode(sha256(convert_to(t.sealed_tokens,'UTF8')),'hex')=c.predecessor_hash
 THEN RETURN false; END IF;
 UPDATE public.portal_gsc_refresh_lab_claims x SET status='completed',
 successor_hash=encode(sha256(convert_to(t.sealed_tokens,'UTF8')),'hex'),
 successor_issued=t.issued_at,successor_expires=t.expires_at WHERE x.claim_id=p_claim;
 RETURN true;
END;
$complete$;

CREATE FUNCTION public.portal_gsc_refresh_atomic_commit() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER
SET search_path=pg_catalog,pg_temp SET row_security=on
AS $atomic$
DECLARE c public.portal_gsc_refresh_lab_claims%ROWTYPE;
BEGIN
 IF NEW.status NOT IN('writing','completed') THEN RETURN NULL; END IF;
 SELECT * INTO c FROM public.portal_gsc_refresh_lab_claims x WHERE x.claim_id=NEW.claim_id;
 IF NOT FOUND OR c.status<>'completed' OR c.write_txid IS DISTINCT FROM txid_current()
 OR NOT EXISTS(
   SELECT 1 FROM public.portal_gsc_oauth_token_candidates t
   JOIN public.portal_gsc_property_grants g USING(tenant_ref,actor_ref,property_fingerprint)
   WHERE t.tenant_ref=c.tenant_ref AND t.actor_ref=c.actor_ref AND t.property_fingerprint=c.property_fingerprint
   AND t.revoked_at IS NULL AND g.revoked_at IS NULL AND g.grant_status='active'
   AND t.issued_at=c.successor_issued AND g.verified_at=c.successor_issued
   AND t.expires_at=c.successor_expires AND g.expires_at=c.successor_expires
   AND t.scope='https://www.googleapis.com/auth/webmasters.readonly' AND g.scope=t.scope
   AND encode(sha256(convert_to(t.sealed_tokens,'UTF8')),'hex')=c.successor_hash
   AND c.successor_hash<>c.predecessor_hash)
 THEN RAISE EXCEPTION 'GSC renewal requires one complete atomic successor' USING ERRCODE='23514'; END IF;
 RETURN NULL;
END;
$atomic$;
CREATE CONSTRAINT TRIGGER portal_gsc_refresh_atomic_successor
 AFTER UPDATE OF status ON public.portal_gsc_refresh_lab_claims
 DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.portal_gsc_refresh_atomic_commit();

-- SET CONSTRAINTS can flush a deferred check early. Once complete, keep
-- BOTH successor rows immutable until this transaction ends. Revocation
-- in a subsequent transaction retains the original revoke-only contract.
CREATE FUNCTION public.portal_gsc_refresh_completed_pair_immutable() RETURNS trigger
LANGUAGE plpgsql SECURITY DEFINER
SET search_path=pg_catalog,pg_temp SET row_security=on
AS $immutable$
BEGIN
 IF EXISTS(SELECT 1 FROM public.portal_gsc_refresh_lab_claims c
   WHERE c.tenant_ref=OLD.tenant_ref AND c.actor_ref=OLD.actor_ref
   AND c.property_fingerprint=OLD.property_fingerprint
   AND c.status='completed' AND c.write_txid=txid_current())
 THEN RAISE EXCEPTION 'Completed renewal pair is immutable until commit' USING ERRCODE='23514'; END IF;
 IF TG_OP='DELETE' THEN RETURN OLD; END IF;
 RETURN NEW;
END;
$immutable$;
CREATE TRIGGER portal_gsc_refresh_token_completed_guard
 BEFORE UPDATE OR DELETE ON public.portal_gsc_oauth_token_candidates
 FOR EACH ROW EXECUTE FUNCTION public.portal_gsc_refresh_completed_pair_immutable();
CREATE TRIGGER portal_gsc_refresh_grant_completed_guard
 BEFORE UPDATE OR DELETE ON public.portal_gsc_property_grants
 FOR EACH ROW EXECUTE FUNCTION public.portal_gsc_refresh_completed_pair_immutable();

ALTER FUNCTION public.portal_gsc_refresh_claim(text,uuid,text) OWNER TO portal_gsc_refresh_owner;
ALTER FUNCTION public.portal_gsc_refresh_begin_replace(uuid,text,uuid) OWNER TO portal_gsc_refresh_owner;
ALTER FUNCTION public.portal_gsc_refresh_insert_scope(text,text,text) OWNER TO portal_gsc_refresh_owner;
ALTER FUNCTION public.portal_gsc_refresh_complete(uuid,text,uuid) OWNER TO portal_gsc_refresh_owner;
ALTER FUNCTION public.portal_gsc_refresh_atomic_commit() OWNER TO portal_gsc_refresh_owner;
ALTER FUNCTION public.portal_gsc_refresh_completed_pair_immutable() OWNER TO portal_gsc_refresh_owner;
REVOKE ALL ON FUNCTION public.portal_gsc_refresh_claim(text,uuid,text),
 public.portal_gsc_refresh_begin_replace(uuid,text,uuid),
 public.portal_gsc_refresh_insert_scope(text,text,text),
 public.portal_gsc_refresh_complete(uuid,text,uuid),
 public.portal_gsc_refresh_atomic_commit(),
 public.portal_gsc_refresh_completed_pair_immutable() FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.portal_gsc_refresh_claim(text,uuid,text),
 public.portal_gsc_refresh_begin_replace(uuid,text,uuid),
 public.portal_gsc_refresh_insert_scope(text,text,text),
 public.portal_gsc_refresh_complete(uuid,text,uuid) TO portal_gsc_f114_runtime;
REVOKE CREATE ON SCHEMA public FROM portal_gsc_refresh_owner;

-- Keep the original state proof for INITIAL inserts. The alternative applies
-- only to this exact claim, actor/property and open replacement transaction.
ALTER POLICY portal_http_lab_token_state ON public.portal_gsc_oauth_token_candidates
 WITH CHECK(public.portal_gsc_http_kernel_scope_valid(tenant_ref,actor_ref,true)
 OR public.portal_gsc_refresh_insert_scope(tenant_ref,actor_ref,property_fingerprint));
ALTER POLICY portal_http_lab_grant_state ON public.portal_gsc_property_grants
 WITH CHECK(public.portal_gsc_http_kernel_scope_valid(tenant_ref,actor_ref,true)
 OR public.portal_gsc_refresh_insert_scope(tenant_ref,actor_ref,property_fingerprint));
COMMIT;
