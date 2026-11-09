-- F8: test negativo de autoridade em segundo passo do CI, apos build Astro.
-- A revogacao real deve vir da autoridade de acesso, nao deste SQL demo.
\set ON_ERROR_STOP on
UPDATE commercial_publishing_lab.publication_fence
SET enabled=false,generation=generation+1,changed_at=now()
WHERE organization_id='tenant_imagine_01';

DO $revoked$
DECLARE denied boolean:=false;
BEGIN
  BEGIN
    PERFORM commercial_publishing_lab.reserve_dispatch_once(
      'tenant_imagine_01','release_20261109_parity',
      (SELECT payload_sha256 FROM commercial_publishing_lab.releases
       WHERE organization_id='tenant_imagine_01'),
      'dispatch_revoked_01'
    );
  EXCEPTION WHEN raise_exception THEN denied:=true; END;
  IF NOT denied THEN RAISE EXCEPTION 'revoked authority accepted new dispatch'; END IF;
END $revoked$;

UPDATE commercial_publishing_lab.publication_fence
SET enabled=true,changed_at=now()
WHERE organization_id='tenant_imagine_01';

DO $stale$
DECLARE denied boolean:=false;
BEGIN
  BEGIN
    PERFORM commercial_publishing_lab.reserve_dispatch_once(
      'tenant_imagine_01','release_20261109_parity',
      (SELECT payload_sha256 FROM commercial_publishing_lab.releases
       WHERE organization_id='tenant_imagine_01'),
      'dispatch_stale_01'
    );
  EXCEPTION WHEN raise_exception THEN denied:=true; END;
  IF NOT denied THEN RAISE EXCEPTION 'stale approved release was revived'; END IF;

  IF (SELECT count(*) FROM commercial_publishing_lab.dispatch_receipts
      WHERE organization_id='tenant_imagine_01') <> 1 THEN
    RAISE EXCEPTION 'revoked release duplicated dispatch receipt';
  END IF;
  RAISE NOTICE 'F8: disabled authorization and reactivation rejected stale release; original receipt remains unchanged.';
END $stale$;
