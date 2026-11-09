-- F8: release PHP e ledger ja gravados em F6. Tentativa reservada no banco
-- antes do build Astro (simulacao; nao tem publicacao externa).
\set ON_ERROR_STOP on
BEGIN;
INSERT INTO commercial_publishing_lab.publication_fence
  (organization_id,generation,enabled)
VALUES ('tenant_imagine_01',1,true);

INSERT INTO commercial_publishing_lab.release_fence
  (organization_id,release_id,payload_sha256,generation)
VALUES ('tenant_imagine_01',:'release_id',:'payload_sha256',1);
COMMIT;

SELECT commercial_publishing_lab.reserve_dispatch_once(
  'tenant_imagine_01',:'release_id',:'payload_sha256','dispatch_parity_20261109'
) AS allowed_once \gset
\if :allowed_once
  \echo 'F8: approved PHP release with matching digest got one pending build reservation.'
\else
  \echo 'F8 ERROR: first reservation was not allowed'
  \quit 3
\endif

SELECT commercial_publishing_lab.reserve_dispatch_once(
  'tenant_imagine_01',:'release_id',:'payload_sha256','dispatch_parity_20261109'
) AS allowed_again \gset
\if :allowed_again
  \echo 'F8 ERROR: duplicate reservation was accepted'
  \quit 3
\else
  \echo 'F8: duplicate reservation correctly remains idempotent.'
\endif

-- Nao permitir ao role de consulta que simule reserva de dispatch.
BEGIN;
SET LOCAL ROLE commercial_lab_reader;
SET LOCAL commercial_lab.organization_id='tenant_imagine_01';
DO $check$
DECLARE denied boolean:=false;
BEGIN
  BEGIN
    PERFORM commercial_publishing_lab.reserve_dispatch_once(
      'tenant_imagine_01','release_20261109_parity',repeat('0',64),'dispatch_intruder_01'
    );
  EXCEPTION WHEN insufficient_privilege THEN denied:=true; END;
  IF NOT denied THEN RAISE EXCEPTION 'untrusted reader executed privileged reserve function'; END IF;
END $check$;
COMMIT;
