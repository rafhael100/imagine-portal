-- F28 — isolated PostgreSQL 18 laboratory ONLY. NOT a Laravel migration.
-- NO production grants. A no-login role receives EXECUTE solely on two
-- SECURITY DEFINER functions. All other F7/F11/F26 functions stay denied.
-- Executing the consume wrapper still assumes trusted PHP verified Ed25519;
-- SQL itself does NOT verify signatures.
\set ON_ERROR_STOP on
BEGIN;

CREATE ROLE commercial_lab_receipt_consumer NOLOGIN NOBYPASSRLS;
GRANT USAGE ON SCHEMA commercial_publishing_lab TO commercial_lab_receipt_consumer;

-- Locks the publication fence + lease for the caller's full transaction.
-- Recovers expected scope from trusted database state, not signed claims.
CREATE FUNCTION commercial_publishing_lab.read_current_delivery_scope(
  p_org text, p_release text
) RETURNS jsonb
LANGUAGE plpgsql SECURITY DEFINER
SET search_path=pg_catalog,commercial_publishing_lab
AS $f28$
DECLARE
  fence_gen bigint; enabled_now boolean;
  r record;
BEGIN
  IF p_org IS NULL OR p_release IS NULL OR
     p_org !~ '^[a-z][a-z0-9_-]{5,79}$' OR
     p_release !~ '^[a-z][a-z0-9_-]{5,79}$' THEN
    RAISE EXCEPTION 'scope: invalid trusted identifiers';
  END IF;
  SELECT f.generation,f.enabled INTO fence_gen,enabled_now
    FROM commercial_publishing_lab.publication_fence f
    WHERE f.organization_id=p_org FOR UPDATE;
  IF NOT FOUND OR enabled_now IS DISTINCT FROM true THEN
    RAISE EXCEPTION 'scope: publication authority missing or disabled';
  END IF;

  SELECT d.payload_sha256,d.dispatch_id,d.generation AS dispatch_gen,
         b.tree_sha256,b.destination_id,b.environment,
         l.authority_generation AS lease_gen,l.attempt_number,l.lease_until,
         l.acknowledged_at,c.authority_generation AS challenge_gen,c.nonce
    INTO r
    FROM commercial_publishing_lab.dispatch_receipts d
    JOIN commercial_publishing_lab.verified_build_targets b
      ON b.organization_id=d.organization_id AND b.release_id=d.release_id
       AND b.payload_sha256=d.payload_sha256
       AND b.authority_generation=d.generation
    JOIN commercial_publishing_lab.delivery_leases l
      ON l.organization_id=d.organization_id AND l.release_id=d.release_id
       AND l.payload_sha256=d.payload_sha256
    JOIN commercial_publishing_lab.delivery_receipt_challenges c
      ON c.organization_id=d.organization_id AND c.release_id=d.release_id
       AND c.attempt_number=l.attempt_number
    WHERE d.organization_id=p_org AND d.release_id=p_release
    FOR UPDATE OF l;
  IF NOT FOUND OR r.acknowledged_at IS NOT NULL OR
     r.lease_until <= clock_timestamp() THEN
    RAISE EXCEPTION 'scope: no live challenged delivery';
  END IF;
  IF r.dispatch_gen IS DISTINCT FROM fence_gen OR
     r.lease_gen IS DISTINCT FROM fence_gen OR
     r.challenge_gen IS DISTINCT FROM fence_gen THEN
    RAISE EXCEPTION 'scope: stale authority generation';
  END IF;

  RETURN jsonb_build_object(
    'organizationId',p_org,
    'releaseId',p_release,
    'payloadSha256',r.payload_sha256,
    'treeSha256',r.tree_sha256,
    'dispatchId',r.dispatch_id,
    'generation',fence_gen,
    'attempt',r.attempt_number,
    'destinationId',r.destination_id,
    'environment',r.environment,
    'nonce',r.nonce
  );
END $f28$;

-- Exactly the previously checked nonce/ack procedure under trusted owner
-- rights, but with no direct table grants for the invoker.
-- WARNING: must only be called by PHP that first verified the signed receipt.
CREATE FUNCTION commercial_publishing_lab.commit_verified_delivery_receipt(
  p_org text,p_release text,p_payload text,p_tree text,p_dispatch text,
  p_generation bigint,p_attempt bigint,p_destination text,p_environment text,
  p_nonce text,p_observed_at timestamptz
) RETURNS boolean
LANGUAGE sql SECURITY DEFINER
SET search_path=pg_catalog,commercial_publishing_lab
AS $f28$
  SELECT commercial_publishing_lab.consume_preverified_delivery_receipt(
    p_org,p_release,p_payload,p_tree,p_dispatch,p_generation,p_attempt,
    p_destination,p_environment,p_nonce,p_observed_at
  );
$f28$;

REVOKE ALL ON FUNCTION commercial_publishing_lab.read_current_delivery_scope(text,text)
  FROM PUBLIC;
REVOKE ALL ON FUNCTION commercial_publishing_lab.commit_verified_delivery_receipt(
  text,text,text,text,text,bigint,bigint,text,text,text,timestamptz
) FROM PUBLIC;

GRANT EXECUTE ON FUNCTION commercial_publishing_lab.read_current_delivery_scope(text,text)
  TO commercial_lab_receipt_consumer;
GRANT EXECUTE ON FUNCTION commercial_publishing_lab.commit_verified_delivery_receipt(
  text,text,text,text,text,bigint,bigint,text,text,text,timestamptz
) TO commercial_lab_receipt_consumer;

-- The consumer has absolutely no direct table grants. We DO NOT grant
-- membership in reader roles, since those expose even tenant-scoped SELECT.
COMMIT;
