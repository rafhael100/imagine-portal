-- PORTAL-F25: disposable PostgreSQL-only concurrency laboratory.
-- Run ONLY after F21 and F24 fixtures, NEVER in a real Portal DB.
-- This lock is a coordination aid, NOT user authorization or durable Z4.
BEGIN;

CREATE FUNCTION f24.synthetic_revocation_writer_gate()
RETURNS trigger
LANGUAGE plpgsql
AS $trigger$
DECLARE
    source_org text;
    fence_key bigint;
BEGIN
    source_org := CASE WHEN TG_OP = 'DELETE' THEN OLD.organization_id ELSE NEW.organization_id END;
    fence_key := CASE source_org
        WHEN 'org-a' THEN 9402400001::bigint
        WHEN 'org-b' THEN 9402400002::bigint
        ELSE NULL::bigint
    END;
    IF fence_key IS NULL THEN
        RAISE EXCEPTION 'F25: unknown synthetic organization for revocation gate';
    END IF;

    -- A writer cannot mutate a session/member while a read holding a
    -- SHARED advisory SESSION lock is in progress. Writer lock is xact-scoped.
    -- Privileged administrators CAN disable triggers; this is not an
    -- independent security authority or production revocation protocol.
    PERFORM pg_catalog.pg_advisory_xact_lock(fence_key);
    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    END IF;
    RETURN NEW;
END
$trigger$;

CREATE TRIGGER f25_session_write
BEFORE INSERT OR UPDATE OR DELETE
ON f24.session_fixture
FOR EACH ROW
EXECUTE FUNCTION f24.synthetic_revocation_writer_gate();

CREATE TRIGGER f25_membership_write
BEFORE INSERT OR UPDATE OR DELETE
ON f24.membership_fixture
FOR EACH ROW
EXECUTE FUNCTION f24.synthetic_revocation_writer_gate();

COMMIT;
