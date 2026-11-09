-- F26-B: Postgres 18 DESCARTAVEL. NUNCA usar como migration no Portal.
-- Fence and nonce ledger supporting a FUTURE independently verified receipt.
-- NO cryptographic verification occurs inside SQL. Only trusted application code
-- that has already called DestinationDeliveryReceiptVerifier may use this gate.
\set ON_ERROR_STOP on
BEGIN;

CREATE TABLE commercial_publishing_lab.verified_build_targets (
  organization_id text NOT NULL,
  release_id text NOT NULL,
  payload_sha256 text NOT NULL CHECK(payload_sha256 ~ '^[a-f0-9]{64}$'),
  tree_sha256 text NOT NULL CHECK(tree_sha256 ~ '^[a-f0-9]{64}$'),
  authority_generation bigint NOT NULL CHECK(authority_generation > 0),
  destination_id text NOT NULL CHECK(destination_id ~ '^[a-z][a-z0-9_-]{5,79}$'),
  environment text NOT NULL CHECK(environment IN ('staging','production')),
  PRIMARY KEY(organization_id,release_id),
  FOREIGN KEY(organization_id,release_id,payload_sha256,authority_generation)
    REFERENCES commercial_publishing_lab.release_fence
      (organization_id,release_id,payload_sha256,generation)
    ON UPDATE RESTRICT ON DELETE RESTRICT
);

CREATE TABLE commercial_publishing_lab.delivery_receipt_challenges (
  organization_id text NOT NULL,
  release_id text NOT NULL,
  authority_generation bigint NOT NULL CHECK(authority_generation > 0),
  attempt_number bigint NOT NULL CHECK(attempt_number > 0),
  nonce text NOT NULL UNIQUE CHECK(nonce ~ '^[a-f0-9]{32}$'),
  issued_at timestamptz NOT NULL DEFAULT clock_timestamp(),
  PRIMARY KEY(organization_id,release_id,attempt_number),
  UNIQUE(organization_id,release_id,attempt_number,nonce),
  FOREIGN KEY(organization_id,release_id)
    REFERENCES commercial_publishing_lab.delivery_leases(organization_id,release_id)
    ON UPDATE RESTRICT ON DELETE RESTRICT
);

CREATE TABLE commercial_publishing_lab.consumed_delivery_receipts (
  organization_id text NOT NULL,
  release_id text NOT NULL,
  payload_sha256 text NOT NULL CHECK(payload_sha256 ~ '^[a-f0-9]{64}$'),
  tree_sha256 text NOT NULL CHECK(tree_sha256 ~ '^[a-f0-9]{64}$'),
  authority_generation bigint NOT NULL,
  attempt_number bigint NOT NULL,
  destination_id text NOT NULL,
  environment text NOT NULL,
  nonce text NOT NULL UNIQUE CHECK(nonce ~ '^[a-f0-9]{32}$'),
  observed_at timestamptz NOT NULL,
  consumed_at timestamptz NOT NULL DEFAULT clock_timestamp(),
  PRIMARY KEY(organization_id,release_id),
  FOREIGN KEY(organization_id,release_id,attempt_number,nonce)
    REFERENCES commercial_publishing_lab.delivery_receipt_challenges
      (organization_id,release_id,attempt_number,nonce)
    ON UPDATE RESTRICT ON DELETE RESTRICT
);

CREATE TRIGGER immutable_build_targets
  BEFORE UPDATE OR DELETE ON commercial_publishing_lab.verified_build_targets
  FOR EACH ROW EXECUTE FUNCTION commercial_publishing_lab.prevent_history_rewrite();
CREATE TRIGGER immutable_receipt_challenges
  BEFORE UPDATE OR DELETE ON commercial_publishing_lab.delivery_receipt_challenges
  FOR EACH ROW EXECUTE FUNCTION commercial_publishing_lab.prevent_history_rewrite();
CREATE TRIGGER immutable_consumed_receipts
  BEFORE UPDATE OR DELETE ON commercial_publishing_lab.consumed_delivery_receipts
  FOR EACH ROW EXECUTE FUNCTION commercial_publishing_lab.prevent_history_rewrite();

-- Serializes nonce creation with revocation and lease retry. Nonces MUST be
-- selected by the trusted Portal dispatcher, not an untrusted request.
CREATE FUNCTION commercial_publishing_lab.issue_delivery_receipt_challenge(
  p_org text,p_release text,p_digest text,p_attempt bigint
) RETURNS text LANGUAGE plpgsql SECURITY INVOKER AS $lab$
DECLARE
  current_gen bigint; enabled_now boolean;
  lease_gen bigint; lease_attempt bigint; lease_digest text;
  lease_expires timestamptz; lease_acked timestamptz;
  current_nonce text;
BEGIN
  SELECT generation,enabled INTO current_gen,enabled_now
    FROM commercial_publishing_lab.publication_fence
    WHERE organization_id=p_org FOR UPDATE;
  IF NOT FOUND OR enabled_now IS DISTINCT FROM true THEN
    RAISE EXCEPTION 'receipt: authority disabled';
  END IF;
  SELECT authority_generation,attempt_number,payload_sha256,lease_until,acknowledged_at
    INTO lease_gen,lease_attempt,lease_digest,lease_expires,lease_acked
    FROM commercial_publishing_lab.delivery_leases
    WHERE organization_id=p_org AND release_id=p_release FOR UPDATE;
  IF NOT FOUND OR lease_gen IS DISTINCT FROM current_gen OR
     lease_attempt IS DISTINCT FROM p_attempt OR
     lease_digest IS DISTINCT FROM p_digest OR
     lease_expires <= clock_timestamp() OR lease_acked IS NOT NULL THEN
    RAISE EXCEPTION 'receipt: no current eligible lease';
  END IF;
  SELECT nonce INTO current_nonce
    FROM commercial_publishing_lab.delivery_receipt_challenges
    WHERE organization_id=p_org AND release_id=p_release
      AND attempt_number=p_attempt;
  IF FOUND THEN RETURN current_nonce; END IF;
  INSERT INTO commercial_publishing_lab.delivery_receipt_challenges
    (organization_id,release_id,authority_generation,attempt_number,nonce)
  VALUES(p_org,p_release,current_gen,p_attempt,replace(gen_random_uuid()::text,'-',''))
  RETURNING nonce INTO current_nonce;
  RETURN current_nonce;
END $lab$;

-- PRECONDITION external to SQL: an independent destination signature was
-- already validated with DestinationDeliveryReceiptVerifier, using this exact
-- trusted lease/fence/nonce context. Only a restricted service may ever call
-- this function in a future operational design.
CREATE FUNCTION commercial_publishing_lab.consume_preverified_delivery_receipt(
  p_org text,p_release text,p_payload text,p_tree text,p_dispatch text,
  p_generation bigint,p_attempt bigint,p_destination text,p_environment text,
  p_nonce text,p_observed_at timestamptz
) RETURNS boolean LANGUAGE plpgsql SECURITY INVOKER AS $lab$
DECLARE
  current_gen bigint; enabled_now boolean;
  dispatch_gen bigint; dispatch_digest text; receipt_dispatch_id text;
  build_tree text; build_destination text; build_environment text;
  lease_gen bigint; lease_attempt bigint; lease_digest text;
  lease_expires timestamptz; lease_acked timestamptz;
BEGIN
  IF p_nonce !~ '^[a-f0-9]{32}$' OR
     p_tree !~ '^[a-f0-9]{64}$' OR
     p_payload !~ '^[a-f0-9]{64}$' OR
     p_generation IS NULL OR p_generation < 1 OR
     p_attempt IS NULL OR p_attempt < 1 OR p_observed_at IS NULL THEN
    RAISE EXCEPTION 'receipt: invalid verified claim';
  END IF;
  SELECT generation,enabled INTO current_gen,enabled_now
    FROM commercial_publishing_lab.publication_fence
    WHERE organization_id=p_org FOR UPDATE;
  IF NOT FOUND OR enabled_now IS DISTINCT FROM true OR
     current_gen IS DISTINCT FROM p_generation THEN
    RAISE EXCEPTION 'receipt: revoked or stale authority generation';
  END IF;
  SELECT r.generation,r.payload_sha256,r.dispatch_id
    INTO dispatch_gen,dispatch_digest,receipt_dispatch_id
    FROM commercial_publishing_lab.dispatch_receipts r
    WHERE r.organization_id=p_org AND r.release_id=p_release;
  IF NOT FOUND OR dispatch_gen IS DISTINCT FROM p_generation OR
     dispatch_digest IS DISTINCT FROM p_payload OR
     receipt_dispatch_id IS DISTINCT FROM p_dispatch THEN
    RAISE EXCEPTION 'receipt: dispatch identity mismatch';
  END IF;
  SELECT tree_sha256,destination_id,environment
    INTO build_tree,build_destination,build_environment
    FROM commercial_publishing_lab.verified_build_targets
    WHERE organization_id=p_org AND release_id=p_release
      AND payload_sha256=p_payload AND authority_generation=p_generation;
  IF NOT FOUND OR build_tree IS DISTINCT FROM p_tree OR
     build_destination IS DISTINCT FROM p_destination OR
     build_environment IS DISTINCT FROM p_environment THEN
    RAISE EXCEPTION 'receipt: build or destination mismatch';
  END IF;
  SELECT authority_generation,attempt_number,payload_sha256,lease_until,acknowledged_at
    INTO lease_gen,lease_attempt,lease_digest,lease_expires,lease_acked
    FROM commercial_publishing_lab.delivery_leases
    WHERE organization_id=p_org AND release_id=p_release FOR UPDATE;
  IF NOT FOUND OR lease_gen IS DISTINCT FROM p_generation OR
     lease_attempt IS DISTINCT FROM p_attempt OR
     lease_digest IS DISTINCT FROM p_payload OR
     lease_expires <= clock_timestamp() OR lease_acked IS NOT NULL THEN
    RAISE EXCEPTION 'receipt: stale, expired or already acknowledged lease';
  END IF;
  IF NOT EXISTS(
    SELECT 1 FROM commercial_publishing_lab.delivery_receipt_challenges c
    WHERE c.organization_id=p_org AND c.release_id=p_release
      AND c.authority_generation=p_generation AND c.attempt_number=p_attempt
      AND c.nonce=p_nonce
  ) THEN RAISE EXCEPTION 'receipt: nonce was not issued for this attempt'; END IF;
  IF p_observed_at < clock_timestamp()-interval '5 minutes' OR
     p_observed_at > clock_timestamp()+interval '30 seconds' THEN
    RAISE EXCEPTION 'receipt: observation is stale or in the future';
  END IF;
  INSERT INTO commercial_publishing_lab.consumed_delivery_receipts
    (organization_id,release_id,payload_sha256,tree_sha256,
     authority_generation,attempt_number,destination_id,environment,
     nonce,observed_at)
  VALUES(p_org,p_release,p_payload,p_tree,
         p_generation,p_attempt,p_destination,p_environment,p_nonce,p_observed_at);
  IF commercial_publishing_lab.ack_delivery_lease(
    p_org,p_release,p_payload,p_attempt
  ) IS DISTINCT FROM true THEN
    RAISE EXCEPTION 'receipt: ack was not committed';
  END IF;
  RETURN true;
END $lab$;

REVOKE ALL ON FUNCTION commercial_publishing_lab.issue_delivery_receipt_challenge(text,text,text,bigint) FROM PUBLIC;
REVOKE ALL ON FUNCTION commercial_publishing_lab.consume_preverified_delivery_receipt(
  text,text,text,text,text,bigint,bigint,text,text,text,timestamptz
) FROM PUBLIC;

-- Read-only visibility for already authenticated synthetic tenant context.
-- NO grants on functions or write permissions are issued to a runtime service.
ALTER TABLE commercial_publishing_lab.verified_build_targets ENABLE ROW LEVEL SECURITY;
ALTER TABLE commercial_publishing_lab.verified_build_targets FORCE ROW LEVEL SECURITY;
ALTER TABLE commercial_publishing_lab.delivery_receipt_challenges ENABLE ROW LEVEL SECURITY;
ALTER TABLE commercial_publishing_lab.delivery_receipt_challenges FORCE ROW LEVEL SECURITY;
ALTER TABLE commercial_publishing_lab.consumed_delivery_receipts ENABLE ROW LEVEL SECURITY;
ALTER TABLE commercial_publishing_lab.consumed_delivery_receipts FORCE ROW LEVEL SECURITY;

CREATE POLICY read_verified_build_targets ON commercial_publishing_lab.verified_build_targets
  FOR SELECT TO commercial_lab_reader
  USING(organization_id=nullif(current_setting('commercial_lab.organization_id',true),''));
CREATE POLICY read_delivery_receipt_challenges ON commercial_publishing_lab.delivery_receipt_challenges
  FOR SELECT TO commercial_lab_reader
  USING(organization_id=nullif(current_setting('commercial_lab.organization_id',true),''));
CREATE POLICY read_consumed_delivery_receipts ON commercial_publishing_lab.consumed_delivery_receipts
  FOR SELECT TO commercial_lab_reader
  USING(organization_id=nullif(current_setting('commercial_lab.organization_id',true),''));
GRANT SELECT ON commercial_publishing_lab.verified_build_targets,
  commercial_publishing_lab.delivery_receipt_challenges,
  commercial_publishing_lab.consumed_delivery_receipts TO commercial_lab_reader;

COMMIT;
