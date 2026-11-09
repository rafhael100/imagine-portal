-- F26-B — Postgres 18 disposable CI. Signed receipt verification is performed
-- separately by PHP; this test models the trusted service handoff ONLY.
\set ON_ERROR_STOP on
INSERT INTO commercial_publishing_lab.verified_build_targets
  (organization_id,release_id,payload_sha256,tree_sha256,
   authority_generation,destination_id,environment)
VALUES ('tenant_imagine_01',:'release_id',:'payload_sha256',:'tree_sha256',
        1,'cloudflare_staging_01','staging');

DO $f26$
DECLARE
  release text; digest text; tree_hash text;
  first_nonce text; second_nonce text; attempt_no bigint;
  denied boolean; obs timestamptz := clock_timestamp();
BEGIN
  SELECT release_id,payload_sha256,tree_sha256
    INTO release,digest,tree_hash
    FROM commercial_publishing_lab.verified_build_targets
    WHERE organization_id='tenant_imagine_01';
  IF release IS NULL THEN RAISE EXCEPTION 'F26: missing approved build target'; END IF;

  attempt_no := commercial_publishing_lab.acquire_delivery_lease(
    'tenant_imagine_01',release,digest
  );
  IF attempt_no <> 1 THEN RAISE EXCEPTION 'F26: initial lease missing'; END IF;
  first_nonce := commercial_publishing_lab.issue_delivery_receipt_challenge(
    'tenant_imagine_01',release,digest,attempt_no
  );
  IF first_nonce !~ '^[a-f0-9]{32}$' THEN
    RAISE EXCEPTION 'F26: nonce is not a random-looking 128-bit token';
  END IF;
  IF commercial_publishing_lab.issue_delivery_receipt_challenge(
    'tenant_imagine_01',release,digest,attempt_no
  ) IS DISTINCT FROM first_nonce THEN
    RAISE EXCEPTION 'F26: duplicate challenge was not idempotent';
  END IF;

  -- Crash/retry: old attempt challenge is permanently invalidated, although
  -- it remains in append-only history.
  UPDATE commercial_publishing_lab.delivery_leases
     SET lease_until=clock_timestamp()-interval '1 second'
    WHERE organization_id='tenant_imagine_01' AND release_id=release;
  attempt_no := commercial_publishing_lab.acquire_delivery_lease(
    'tenant_imagine_01',release,digest
  );
  IF attempt_no <> 2 THEN RAISE EXCEPTION 'F26: retry lease not fenced'; END IF;

  denied:=false;
  BEGIN
    PERFORM commercial_publishing_lab.consume_preverified_delivery_receipt(
      'tenant_imagine_01',release,digest,tree_hash,'dispatch_parity_20261109',
      1,1,'cloudflare_staging_01','staging',first_nonce,obs);
  EXCEPTION WHEN raise_exception THEN denied:=true;
  END;
  IF NOT denied THEN RAISE EXCEPTION 'F26: stale attempt was acknowledged'; END IF;

  second_nonce := commercial_publishing_lab.issue_delivery_receipt_challenge(
    'tenant_imagine_01',release,digest,2
  );
  IF second_nonce=first_nonce THEN RAISE EXCEPTION 'F26: retry reused old nonce'; END IF;

  denied:=false;
  BEGIN
    PERFORM commercial_publishing_lab.consume_preverified_delivery_receipt(
      'tenant_imagine_01',release,digest,repeat('0',64),'dispatch_parity_20261109',
      1,2,'cloudflare_staging_01','staging',second_nonce,obs);
  EXCEPTION WHEN raise_exception THEN denied:=true;
  END;
  IF NOT denied THEN RAISE EXCEPTION 'F26: wrong build hash was accepted'; END IF;

  denied:=false;
  BEGIN
    PERFORM commercial_publishing_lab.consume_preverified_delivery_receipt(
      'tenant_imagine_01',release,digest,tree_hash,'dispatch_parity_20261109',
      2,2,'cloudflare_staging_01','staging',second_nonce,obs);
  EXCEPTION WHEN raise_exception THEN denied:=true;
  END;
  IF NOT denied THEN RAISE EXCEPTION 'F26: wrong generation was accepted'; END IF;

  denied:=false;
  BEGIN
    PERFORM commercial_publishing_lab.consume_preverified_delivery_receipt(
      'tenant_imagine_01',release,digest,tree_hash,'dispatch_parity_20261109',
      1,2,'cloudflare_staging_01','staging',repeat('0',32),obs);
  EXCEPTION WHEN raise_exception THEN denied:=true;
  END;
  IF NOT denied THEN RAISE EXCEPTION 'F26: nonce without challenge was accepted'; END IF;

  denied:=false;
  BEGIN
    PERFORM commercial_publishing_lab.consume_preverified_delivery_receipt(
      'tenant_imagine_01',release,digest,tree_hash,'dispatch_wrong_01',
      1,2,'cloudflare_staging_01','staging',second_nonce,obs);
  EXCEPTION WHEN raise_exception THEN denied:=true;
  END;
  IF NOT denied THEN RAISE EXCEPTION 'F26: wrong dispatch was accepted'; END IF;

  denied:=false;
  BEGIN
    PERFORM commercial_publishing_lab.consume_preverified_delivery_receipt(
      'tenant_imagine_01',release,digest,tree_hash,'dispatch_parity_20261109',
      1,2,'cloudflare_other_01','staging',second_nonce,obs);
  EXCEPTION WHEN raise_exception THEN denied:=true;
  END;
  IF NOT denied THEN RAISE EXCEPTION 'F26: wrong destination was accepted'; END IF;

  denied:=false;
  BEGIN
    PERFORM commercial_publishing_lab.consume_preverified_delivery_receipt(
      'tenant_imagine_01',release,digest,tree_hash,'dispatch_parity_20261109',
      1,2,'cloudflare_staging_01','production',second_nonce,obs);
  EXCEPTION WHEN raise_exception THEN denied:=true;
  END;
  IF NOT denied THEN RAISE EXCEPTION 'F26: wrong environment was accepted'; END IF;

  denied:=false;
  BEGIN
    PERFORM commercial_publishing_lab.consume_preverified_delivery_receipt(
      'tenant_imagine_01',release,digest,tree_hash,'dispatch_parity_20261109',
      1,2,'cloudflare_staging_01','staging',second_nonce,
      clock_timestamp()-interval '6 minutes');
  EXCEPTION WHEN raise_exception THEN denied:=true;
  END;
  IF NOT denied THEN RAISE EXCEPTION 'F26: expired observation was accepted'; END IF;

  IF commercial_publishing_lab.consume_preverified_delivery_receipt(
    'tenant_imagine_01',release,digest,tree_hash,'dispatch_parity_20261109',
    1,2,'cloudflare_staging_01','staging',second_nonce,clock_timestamp()
  ) IS DISTINCT FROM true THEN
    RAISE EXCEPTION 'F26: current preverified receipt was not consumed';
  END IF;

  IF (SELECT count(*) FROM commercial_publishing_lab.consumed_delivery_receipts
      WHERE organization_id='tenant_imagine_01' AND release_id=release) <> 1 THEN
    RAISE EXCEPTION 'F26: expected exactly one consumed receipt';
  END IF;
  IF (SELECT acknowledged_at FROM commercial_publishing_lab.delivery_leases
      WHERE organization_id='tenant_imagine_01' AND release_id=release) IS NULL THEN
    RAISE EXCEPTION 'F26: lease not acknowledged within same transaction';
  END IF;
  denied:=false;
  BEGIN
    PERFORM commercial_publishing_lab.consume_preverified_delivery_receipt(
      'tenant_imagine_01',release,digest,tree_hash,'dispatch_parity_20261109',
      1,2,'cloudflare_staging_01','staging',second_nonce,clock_timestamp());
  EXCEPTION WHEN raise_exception THEN denied:=true;
  END;
  IF NOT denied THEN RAISE EXCEPTION 'F26: replayed receipt acknowledged twice'; END IF;

  denied:=false;
  BEGIN
    PERFORM commercial_publishing_lab.issue_delivery_receipt_challenge(
      'tenant_imagine_01',release,digest,2);
  EXCEPTION WHEN raise_exception THEN denied:=true;
  END;
  IF NOT denied THEN RAISE EXCEPTION 'F26: acked lease issued a new challenge'; END IF;
END $f26$;

-- Reader can inspect only its tenant and cannot invoke privileged functions.
BEGIN;
SET LOCAL ROLE commercial_lab_reader;
SET LOCAL commercial_lab.organization_id='tenant_imagine_01';
DO $denied$
DECLARE denied boolean:=false;
BEGIN
  IF (SELECT count(*) FROM commercial_publishing_lab.consumed_delivery_receipts) <> 1 THEN
    RAISE EXCEPTION 'F26: tenant scoped RLS not effective';
  END IF;
  BEGIN
    PERFORM commercial_publishing_lab.issue_delivery_receipt_challenge(
      'tenant_imagine_01','release_20261109_parity',repeat('0',64),1);
  EXCEPTION WHEN insufficient_privilege THEN denied:=true; END;
  IF NOT denied THEN RAISE EXCEPTION 'F26: read-only role issued nonce'; END IF;
END $denied$;
COMMIT;

SELECT 'PASS F26-B: nonce generated per lease attempt, replay/stale/fence/target rejected, one local ack; no remote deployment.' AS result;
