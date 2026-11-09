\set ON_ERROR_STOP on
-- Alpha release 2 already has an approved outbox and F7 dispatch receipt.
DO $test$
DECLARE result bigint; denied boolean:=false;
BEGIN
  IF commercial_publishing_lab.acquire_delivery_lease(
    'tenant_alpha_01','release_alpha_02',repeat('d',64))<>1 THEN
    RAISE EXCEPTION 'first lease was not created'; END IF;
  IF commercial_publishing_lab.acquire_delivery_lease(
    'tenant_alpha_01','release_alpha_02',repeat('d',64))<>0 THEN
    RAISE EXCEPTION 'active lease not marked busy'; END IF;
  -- Simulate worker crash/timeout in disposable fixture.
  UPDATE commercial_publishing_lab.delivery_leases
    SET lease_until=clock_timestamp()-interval '1 second'
    WHERE organization_id='tenant_alpha_01' AND release_id='release_alpha_02';
  SELECT commercial_publishing_lab.acquire_delivery_lease(
    'tenant_alpha_01','release_alpha_02',repeat('d',64)) INTO result;
  IF result<>2 THEN RAISE EXCEPTION 'expired lease did not permit retry'; END IF;
  BEGIN
    PERFORM commercial_publishing_lab.ack_delivery_lease(
      'tenant_alpha_01','release_alpha_02',repeat('d',64),1);
  EXCEPTION WHEN raise_exception THEN denied:=true; END;
  IF NOT denied THEN RAISE EXCEPTION 'old attempt acknowledged after retry'; END IF;
  IF commercial_publishing_lab.ack_delivery_lease(
    'tenant_alpha_01','release_alpha_02',repeat('d',64),2) IS DISTINCT FROM true THEN
    RAISE EXCEPTION 'current attempt not acknowledged locally'; END IF;
  IF commercial_publishing_lab.ack_delivery_lease(
    'tenant_alpha_01','release_alpha_02',repeat('d',64),2) IS DISTINCT FROM false THEN
    RAISE EXCEPTION 'duplicate acknowledgement not idempotent'; END IF;
  IF commercial_publishing_lab.acquire_delivery_lease(
    'tenant_alpha_01','release_alpha_02',repeat('d',64))<>-1 THEN
    RAISE EXCEPTION 'acknowledged delivery acquired again'; END IF;
END $test$;

-- Gamma was reserved by the F7 race. Its lease may not be acked after revoke.
DO $revoke$
DECLARE denied boolean:=false;
BEGIN
  IF commercial_publishing_lab.acquire_delivery_lease(
    'tenant_gamma_01','release_gamma_01',repeat('c',64))<>1 THEN
    RAISE EXCEPTION 'gamma lease not acquired'; END IF;
  UPDATE commercial_publishing_lab.publication_fence
    SET enabled=false,generation=generation+1,changed_at=clock_timestamp()
    WHERE organization_id='tenant_gamma_01';
  BEGIN
    PERFORM commercial_publishing_lab.ack_delivery_lease(
      'tenant_gamma_01','release_gamma_01',repeat('c',64),1);
  EXCEPTION WHEN raise_exception THEN denied:=true; END;
  IF NOT denied THEN RAISE EXCEPTION 'ack after revocation was accepted'; END IF;
END $revoke$;

-- Beta prepares a separate release for a two-connection lease race test.
INSERT INTO commercial_publishing_lab.publish_outbox
  (organization_id,release_id,payload_sha256,publisher_actor,queued_at,idempotency_key)
VALUES ('tenant_beta_01','release_beta_01',repeat('b',64),
        'publisher_beta_01','2026-11-09 10:30:00+00','publish_beta_01');
DO $reserve$
BEGIN
  IF commercial_publishing_lab.reserve_dispatch_once(
    'tenant_beta_01','release_beta_01',repeat('b',64),'dispatch_beta_01'
  ) IS DISTINCT FROM true THEN RAISE EXCEPTION 'beta receipt not reserved'; END IF;
END $reserve$;

BEGIN;
SET LOCAL ROLE commercial_lab_reader;
SET LOCAL commercial_lab.organization_id='tenant_gamma_01';
DO $rls$
DECLARE denied boolean:=false;
BEGIN
  IF (SELECT count(*) FROM commercial_publishing_lab.delivery_leases)<>1 THEN
    RAISE EXCEPTION 'tenant leak in delivery lease reader'; END IF;
  BEGIN
    PERFORM commercial_publishing_lab.acquire_delivery_lease(
      'tenant_beta_01','release_beta_01',repeat('b',64));
  EXCEPTION WHEN insufficient_privilege THEN denied:=true; END;
  IF NOT denied THEN RAISE EXCEPTION 'read role acquired delivery lease'; END IF;
END $rls$;
COMMIT;
SELECT 'PASS F11: crash recovery, stale ack, revoke, idempotence, reader RLS' AS result;
