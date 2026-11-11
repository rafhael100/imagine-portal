-- PORTAL-F127: disposable PG18-only privilege rehearsal.
-- F125's tenant VIEW cannot remain directly queryable by worker roles once
-- a human reviewer access boundary is introduced. Never deploy this SQL.
BEGIN;
DO $f127$
BEGIN
  IF current_database() <> 'portal_commercial_lab'
     OR to_regclass('portal_commercial_ci.delivery_review_queue') IS NULL
     OR to_regclass('portal_commercial_ci.delivery_gate') IS NULL
  THEN
    RAISE EXCEPTION 'F127 requires isolated F125 lab in portal_commercial_lab';
  END IF;
END;
$f127$;

CREATE ROLE portal_f127_review_owner NOLOGIN NOINHERIT NOSUPERUSER
  NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
CREATE ROLE portal_f127_reviewer_a NOLOGIN NOINHERIT NOSUPERUSER
  NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
CREATE ROLE portal_f127_reviewer_b NOLOGIN NOINHERIT NOSUPERUSER
  NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;

GRANT USAGE ON SCHEMA portal_commercial_ci TO
  portal_f127_review_owner, portal_f127_reviewer_a, portal_f127_reviewer_b;

-- Close bypass paths from F125: workers cannot SELECT the view or its source.
-- Their F122/F123 guarded write functions remain unchanged.
REVOKE SELECT ON portal_commercial_ci.delivery_review_queue
  FROM portal_f122_worker_a, portal_f122_worker_b;
REVOKE SELECT ON portal_commercial_ci.delivery_gate
  FROM portal_f122_worker_a, portal_f122_worker_b;

-- Authorization fixture is not a real identity provider. Only synthetic
-- source-side administrator test writes to it. No bearer, email or token.
CREATE TABLE portal_commercial_ci.review_authority_lab (
  organization_reference uuid NOT NULL PRIMARY KEY,
  reviewer_reference uuid NOT NULL,
  membership_status text NOT NULL CHECK (membership_status IN ('active','revoked')),
  session_status text NOT NULL CHECK (session_status IN ('active','revoked')),
  mfa_verified_at timestamptz,
  session_expires_at timestamptz NOT NULL,
  session_revision bigint NOT NULL CHECK (session_revision > 0),
  organization_revision bigint NOT NULL CHECK (organization_revision > 0),
  permission_code text NOT NULL CHECK
    (permission_code IN ('commercial.delivery-review.view','services.view'))
);
REVOKE ALL ON portal_commercial_ci.review_authority_lab FROM PUBLIC;
ALTER TABLE portal_commercial_ci.review_authority_lab ENABLE ROW LEVEL SECURITY;
ALTER TABLE portal_commercial_ci.review_authority_lab FORCE ROW LEVEL SECURITY;
GRANT SELECT ON portal_commercial_ci.review_authority_lab
  TO portal_f127_review_owner;

-- Exactly one synthetic tenant and actor fixed at the server boundary.
CREATE POLICY f127_owner_authority_read
  ON portal_commercial_ci.review_authority_lab
  FOR SELECT TO portal_f127_review_owner
  USING (
    organization_reference='11111111-1111-4111-8111-111111111111'::uuid
    AND reviewer_reference='a7a7a7a7-a7a7-47a7-87a7-a7a7a7a7a7a7'::uuid
  );
-- The invoker VIEW must execute under the dedicated non-superuser function
-- owner, which is itself constrained by the source table's FORCE RLS.
GRANT SELECT ON portal_commercial_ci.delivery_gate
  TO portal_f127_review_owner;
GRANT SELECT ON portal_commercial_ci.delivery_review_queue
  TO portal_f127_review_owner;
CREATE POLICY f127_owner_gate_read
  ON portal_commercial_ci.delivery_gate
  FOR SELECT TO portal_f127_review_owner
  USING (organization_reference='11111111-1111-4111-8111-111111111111'::uuid);

CREATE FUNCTION portal_commercial_ci.review_items_lab_a()
RETURNS SETOF portal_commercial_ci.delivery_review_queue
LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path=pg_catalog
AS $f127$
BEGIN
  -- A synthetic review authorization is an explicit CI-only seed; there is
  -- NO automatic grant from a role label, membership or a browser value.
  IF NOT EXISTS (
    SELECT 1 FROM portal_commercial_ci.review_authority_lab AS a
    WHERE a.organization_reference =
      '11111111-1111-4111-8111-111111111111'::uuid
      AND a.reviewer_reference =
      'a7a7a7a7-a7a7-47a7-87a7-a7a7a7a7a7a7'::uuid
      AND a.membership_status='active'
      AND a.session_status='active'
      AND a.mfa_verified_at IS NOT NULL
      AND a.mfa_verified_at<=transaction_timestamp()
      AND a.session_expires_at>transaction_timestamp()
      AND a.session_revision=a.organization_revision
      AND a.permission_code='commercial.delivery-review.view'
  ) THEN
    RETURN;
  END IF;
  RETURN QUERY
    SELECT v.* FROM portal_commercial_ci.delivery_review_queue AS v
    WHERE v.organization_reference=
      '11111111-1111-4111-8111-111111111111'::uuid;
END
$f127$;

-- Never leave a SECURITY DEFINER function owned by the CI superuser.
ALTER FUNCTION portal_commercial_ci.review_items_lab_a()
  OWNER TO portal_f127_review_owner;
REVOKE ALL ON FUNCTION portal_commercial_ci.review_items_lab_a() FROM PUBLIC;
GRANT EXECUTE ON FUNCTION portal_commercial_ci.review_items_lab_a()
  TO portal_f127_reviewer_a;
COMMENT ON FUNCTION portal_commercial_ci.review_items_lab_a() IS
  'Disposable F127 review-only permission rehearsal, no real IAM/CRM or HTTP authorization';
COMMIT;
