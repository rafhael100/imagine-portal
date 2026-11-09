-- Exige F5 schema, F7 schema e os dados ficticios de test-ledger.sql.
\set ON_ERROR_STOP on

INSERT INTO commercial_publishing_lab.publication_fence
  (organization_id,generation,enabled)
VALUES ('tenant_alpha_01',1,true),
       ('tenant_beta_01',1,true),
       ('tenant_gamma_01',1,true);

INSERT INTO commercial_publishing_lab.release_fence
  (organization_id,release_id,payload_sha256,generation)
VALUES ('tenant_alpha_01','release_alpha_01',repeat('a',64),1),
       ('tenant_beta_01','release_beta_01',repeat('b',64),1);

DO $test$
DECLARE ok boolean; denied boolean;
BEGIN
  SELECT commercial_publishing_lab.reserve_dispatch_once(
    'tenant_alpha_01','release_alpha_01',repeat('a',64),'dispatch_alpha_01'
  ) INTO ok;
  IF ok IS DISTINCT FROM true THEN RAISE EXCEPTION 'first dispatch not reserved'; END IF;

  SELECT commercial_publishing_lab.reserve_dispatch_once(
    'tenant_alpha_01','release_alpha_01',repeat('a',64),'dispatch_alpha_01'
  ) INTO ok;
  IF ok IS DISTINCT FROM false THEN RAISE EXCEPTION 'idempotent dispatch duplicated'; END IF;

  denied:=false;
  BEGIN
    PERFORM commercial_publishing_lab.reserve_dispatch_once(
      'tenant_alpha_01','release_beta_01',repeat('b',64),'dispatch_cross_01');
  EXCEPTION WHEN raise_exception THEN denied:=true; END;
  IF NOT denied THEN RAISE EXCEPTION 'cross-tenant dispatch accepted'; END IF;

  denied:=false;
  BEGIN
    PERFORM commercial_publishing_lab.reserve_dispatch_once(
      'tenant_alpha_01','release_alpha_01',repeat('b',64),'dispatch_wronghash_01');
  EXCEPTION WHEN raise_exception THEN denied:=true; END;
  IF NOT denied THEN RAISE EXCEPTION 'wrong hash dispatch accepted'; END IF;
END $test$;

-- Revogacao incrementa geracao, e um mesmo release antigo nunca pode voltar
-- a ser autorizado por reativacao; isto nao protege contra RESTORE completo.
UPDATE commercial_publishing_lab.publication_fence
SET enabled=false,generation=generation+1,changed_at=now()
WHERE organization_id='tenant_alpha_01';

DO $denied$
DECLARE denied boolean:=false;
BEGIN
  BEGIN
    PERFORM commercial_publishing_lab.reserve_dispatch_once(
      'tenant_alpha_01','release_alpha_01',repeat('a',64),'dispatch_after_revoke');
  EXCEPTION WHEN raise_exception THEN denied:=true; END;
  IF NOT denied THEN RAISE EXCEPTION 'revoked authority accepted dispatch'; END IF;
END $denied$;

UPDATE commercial_publishing_lab.publication_fence
SET enabled=true,changed_at=now()
WHERE organization_id='tenant_alpha_01';

DO $old$
DECLARE denied boolean:=false;
BEGIN
  BEGIN
    PERFORM commercial_publishing_lab.reserve_dispatch_once(
      'tenant_alpha_01','release_alpha_01',repeat('a',64),'dispatch_stale_01');
  EXCEPTION WHEN raise_exception THEN denied:=true; END;
  IF NOT denied THEN RAISE EXCEPTION 'stale generation survived revocation'; END IF;
END $old$;

-- Segundo release da mesma organizacao, vinculado somente a geracao atual.
INSERT INTO commercial_publishing_lab.releases
  (organization_id,release_id,payload_sha256,editor_actor,requested_at)
VALUES ('tenant_alpha_01','release_alpha_02',repeat('d',64),
        'editor_alpha_01','2026-11-09 11:00:00+00');
INSERT INTO commercial_publishing_lab.approvals
  (organization_id,release_id,payload_sha256,editor_actor,approver_actor,approved_at)
VALUES ('tenant_alpha_01','release_alpha_02',repeat('d',64),
        'editor_alpha_01','reviewer_alpha_01','2026-11-09 11:10:00+00');
INSERT INTO commercial_publishing_lab.publish_outbox
  (organization_id,release_id,payload_sha256,publisher_actor,queued_at,idempotency_key)
VALUES ('tenant_alpha_01','release_alpha_02',repeat('d',64),
        'publisher_alpha_01','2026-11-09 11:15:00+00','publish_alpha_02');
INSERT INTO commercial_publishing_lab.release_fence
  (organization_id,release_id,payload_sha256,generation)
VALUES ('tenant_alpha_01','release_alpha_02',repeat('d',64),2);

SELECT commercial_publishing_lab.reserve_dispatch_once(
  'tenant_alpha_01','release_alpha_02',repeat('d',64),'dispatch_alpha_02'
) AS successful_new_generation \gset

\if :successful_new_generation
  \echo 'F7: fresh authorized generation reserved dispatch after old generation revoked.'
\else
  \echo 'F7 ERROR: fresh generation was rejected.'
  \quit 3
\endif

-- Preparar gamma para o teste paralelo, SEM reserva inicial.
INSERT INTO commercial_publishing_lab.approvals
  (organization_id,release_id,payload_sha256,editor_actor,approver_actor,approved_at)
VALUES ('tenant_gamma_01','release_gamma_01',repeat('c',64),
        'editor_gamma_01','reviewer_gamma_01','2026-11-09 10:20:00+00');
INSERT INTO commercial_publishing_lab.publish_outbox
  (organization_id,release_id,payload_sha256,publisher_actor,queued_at,idempotency_key)
VALUES ('tenant_gamma_01','release_gamma_01',repeat('c',64),
        'publisher_gamma_01','2026-11-09 10:25:00+00','publish_gamma_01');
INSERT INTO commercial_publishing_lab.release_fence
  (organization_id,release_id,payload_sha256,generation)
VALUES ('tenant_gamma_01','release_gamma_01',repeat('c',64),1);

DO $checks$
BEGIN
  IF (SELECT count(*) FROM commercial_publishing_lab.dispatch_receipts)<>2 THEN
    RAISE EXCEPTION 'wrong local dispatch receipt count';
  END IF;
  IF (SELECT generation FROM commercial_publishing_lab.publication_fence
    WHERE organization_id='tenant_alpha_01')<>2 THEN
    RAISE EXCEPTION 'generation fence did not advance';
  END IF;
END $checks$;

-- O leitor nao pode enxergar reservas de outras organizacoes nem inserir.
BEGIN;
SET LOCAL ROLE commercial_lab_reader;
SET LOCAL commercial_lab.organization_id='tenant_beta_01';
DO $rls$
DECLARE denied boolean:=false;
BEGIN
  IF (SELECT count(*) FROM commercial_publishing_lab.dispatch_receipts)<>0 THEN
    RAISE EXCEPTION 'foreign dispatch receipt leaked through RLS';
  END IF;
  BEGIN
    INSERT INTO commercial_publishing_lab.dispatch_receipts
      (organization_id,release_id,payload_sha256,generation,dispatch_id)
    VALUES ('tenant_beta_01','release_beta_01',repeat('b',64),1,'dispatch_untrusted_01');
  EXCEPTION WHEN insufficient_privilege THEN denied:=true; END;
  IF NOT denied THEN RAISE EXCEPTION 'read role wrote dispatch receipt'; END IF;
END $rls$;
COMMIT;
SELECT 'PASS F7: revocation fence, stale-generation rejection, append-only dispatch ledger and RLS reader' AS status;
