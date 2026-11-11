-- PORTAL-F128: Audit attempts to read unresolved delivery metadata.
-- Only for an ephemeral GitHub-hosted PostgreSQL 18 database. Do NOT deploy.
BEGIN;
DO $f128$
BEGIN
  IF current_database() <> 'portal_commercial_lab'
     OR to_regprocedure('portal_commercial_ci.review_items_lab_a()') IS NULL
     OR to_regclass('portal_commercial_ci.review_authority_lab') IS NULL
     OR to_regclass('portal_commercial_ci.delivery_review_queue') IS NULL
  THEN
    RAISE EXCEPTION 'F128 refuses non-F127 disposable PostgreSQL fixture';
  END IF;
END
$f128$;

CREATE TABLE portal_commercial_ci.review_access_audit_lab (
  event_id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  organization_reference uuid NOT NULL,
  reviewer_reference uuid NOT NULL,
  event_kind text NOT NULL CHECK (event_kind = 'synthetic_review_requested'),
  attempted_at timestamptz NOT NULL DEFAULT transaction_timestamp()
);
REVOKE ALL ON portal_commercial_ci.review_access_audit_lab FROM PUBLIC;
ALTER TABLE portal_commercial_ci.review_access_audit_lab ENABLE ROW LEVEL SECURITY;
ALTER TABLE portal_commercial_ci.review_access_audit_lab FORCE ROW LEVEL SECURITY;

CREATE POLICY f128_owner_audit_insert
  ON portal_commercial_ci.review_access_audit_lab FOR INSERT
  TO portal_f127_review_owner
  WITH CHECK (
    organization_reference='11111111-1111-4111-8111-111111111111'::uuid
    AND reviewer_reference='a7a7a7a7-a7a7-47a7-87a7-a7a7a7a7a7a7'::uuid
    AND event_kind='synthetic_review_requested'
  );
GRANT INSERT ON portal_commercial_ci.review_access_audit_lab
  TO portal_f127_review_owner;

-- Exactly one public-facing (CI-role-facing) entrypoint for lab review.
-- Log *attempted* access, never a false claim that rows were actually read
-- or that the CRM committed anything. SQL logging is transactional: a
-- rollback can remove the event, so it is not a complete production audit.
CREATE FUNCTION portal_commercial_ci.review_and_audit_lab_a()
RETURNS SETOF portal_commercial_ci.delivery_review_queue
LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path = pg_catalog
AS $f128$
BEGIN
  INSERT INTO portal_commercial_ci.review_access_audit_lab
    (organization_reference, reviewer_reference, event_kind)
  VALUES
    ('11111111-1111-4111-8111-111111111111'::uuid,
     'a7a7a7a7-a7a7-47a7-87a7-a7a7a7a7a7a7'::uuid,
     'synthetic_review_requested');

  RETURN QUERY
    SELECT * FROM portal_commercial_ci.review_items_lab_a();
END
$f128$;
ALTER FUNCTION portal_commercial_ci.review_and_audit_lab_a()
  OWNER TO portal_f127_review_owner;

-- Prevent the reader from bypassing the audited wrapper.
REVOKE EXECUTE ON FUNCTION portal_commercial_ci.review_items_lab_a()
  FROM portal_f127_reviewer_a;
REVOKE ALL ON FUNCTION portal_commercial_ci.review_and_audit_lab_a()
  FROM PUBLIC;
GRANT EXECUTE ON FUNCTION portal_commercial_ci.review_and_audit_lab_a()
  TO portal_f127_reviewer_a;
COMMENT ON FUNCTION portal_commercial_ci.review_and_audit_lab_a() IS
  'F128 CI-only review-attempt audit; not durable across caller rollback or a real CRM/Portal authorization.';
COMMIT;
