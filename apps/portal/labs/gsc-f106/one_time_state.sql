-- PORTAL-GSC-F106: disposable PostgreSQL 18 laboratory ONLY.
-- Never apply to a customer database or production migration.
-- State digests are SHA-256 hex of 256-bit CSPRNG state; never store the state.
-- Both functions are callable only by a synthetic loopback lab client.
BEGIN;

CREATE ROLE portal_gsc_f106_owner
  NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
CREATE ROLE portal_gsc_f106_client
  LOGIN PASSWORD 'ci-f106-client-only'
  NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;

GRANT USAGE, CREATE ON SCHEMA public TO portal_gsc_f106_owner;
GRANT USAGE ON SCHEMA public TO portal_gsc_f106_client;
GRANT CONNECT ON DATABASE portal_identity_lab TO portal_gsc_f106_client;

SET ROLE portal_gsc_f106_owner;

CREATE TABLE public.portal_gsc_f106_pending (
  state_digest text PRIMARY KEY CHECK (state_digest ~ '^[a-f0-9]{64}$'),
  actor_id uuid NOT NULL,
  organization_id uuid NOT NULL,
  property_fingerprint text NOT NULL
    CHECK (property_fingerprint ~ '^[a-f0-9]{64}$'),
  issued_at bigint NOT NULL CHECK (issued_at > 0),
  expires_at bigint NOT NULL CHECK (expires_at = issued_at + 300),
  consumed_at bigint
);

ALTER TABLE public.portal_gsc_f106_pending ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.portal_gsc_f106_pending FORCE ROW LEVEL SECURITY;
-- Policy is specific to the NOLOGIN function owner. No browser, app,
-- PUBLIC or client role has table-level rights or a permissive policy.
CREATE POLICY portal_f106_owner_only
  ON public.portal_gsc_f106_pending
  FOR ALL TO portal_gsc_f106_owner
  USING (true) WITH CHECK (true);
REVOKE ALL ON TABLE public.portal_gsc_f106_pending FROM PUBLIC;

CREATE FUNCTION public.portal_gsc_f106_issue(
  p_state_digest text, p_actor uuid, p_organization uuid,
  p_property_fingerprint text, p_issued_at bigint, p_expires_at bigint
) RETURNS boolean
LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path = pg_catalog, pg_temp
AS $f106$
DECLARE
  inserted integer := 0;
BEGIN
  IF p_state_digest IS NULL OR p_state_digest !~ '^[a-f0-9]{64}$'
    OR p_actor IS NULL OR p_organization IS NULL
    OR p_property_fingerprint IS NULL
    OR p_property_fingerprint !~ '^[a-f0-9]{64}$'
    OR p_issued_at IS NULL OR p_issued_at <= 0
    OR p_expires_at IS NULL OR p_expires_at <> p_issued_at + 300
  THEN
    RETURN false;
  END IF;

  INSERT INTO public.portal_gsc_f106_pending
    (state_digest, actor_id, organization_id, property_fingerprint,
      issued_at, expires_at)
  VALUES
    (p_state_digest, p_actor, p_organization, p_property_fingerprint,
      p_issued_at, p_expires_at)
  ON CONFLICT (state_digest) DO NOTHING;
  GET DIAGNOSTICS inserted = ROW_COUNT;
  RETURN inserted = 1;
END;
$f106$;

CREATE FUNCTION public.portal_gsc_f106_consume(
  p_state_digest text, p_actor uuid, p_organization uuid,
  p_property_fingerprint text, p_now bigint
) RETURNS boolean
LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path = pg_catalog, pg_temp
AS $f106$
DECLARE
  accepted boolean := false;
BEGIN
  IF p_state_digest IS NULL OR p_state_digest !~ '^[a-f0-9]{64}$'
    OR p_actor IS NULL OR p_organization IS NULL
    OR p_property_fingerprint IS NULL
    OR p_property_fingerprint !~ '^[a-f0-9]{64}$'
    OR p_now IS NULL OR p_now <= 0
  THEN
    RETURN false;
  END IF;

  -- UPDATE is atomic under PostgreSQL row locks. First attempt consumes
  -- state even if the supplied tenant/actor/property is incorrect.
  -- No verifier, token or actor metadata leaves this function.
  WITH claimed AS (
    UPDATE public.portal_gsc_f106_pending AS pending
       SET consumed_at = p_now
     WHERE pending.state_digest = p_state_digest
       AND pending.consumed_at IS NULL
     RETURNING (
       pending.actor_id = p_actor
       AND pending.organization_id = p_organization
       AND pending.property_fingerprint = p_property_fingerprint
       AND p_now >= pending.issued_at
       AND p_now <= pending.expires_at
     ) AS matched
  )
  SELECT coalesce(bool_or(matched), false) INTO accepted FROM claimed;
  RETURN accepted;
END;
$f106$;

REVOKE ALL ON FUNCTION public.portal_gsc_f106_issue(text,uuid,uuid,text,bigint,bigint) FROM PUBLIC;
REVOKE ALL ON FUNCTION public.portal_gsc_f106_consume(text,uuid,uuid,text,bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.portal_gsc_f106_issue(text,uuid,uuid,text,bigint,bigint)
  TO portal_gsc_f106_client;
GRANT EXECUTE ON FUNCTION public.portal_gsc_f106_consume(text,uuid,uuid,text,bigint)
  TO portal_gsc_f106_client;

RESET ROLE;
REVOKE CREATE ON SCHEMA public FROM portal_gsc_f106_owner;

COMMIT;
