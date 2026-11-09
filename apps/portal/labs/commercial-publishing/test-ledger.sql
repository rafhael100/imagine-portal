-- Laboratório PostgreSQL F5: somente registros fictícios; CLI psql -v ON_ERROR_STOP=1.
\set ON_ERROR_STOP on

INSERT INTO commercial_publishing_lab.releases
(organization_id,release_id,payload_sha256,editor_actor,requested_at)
VALUES
('tenant_alpha_01','release_alpha_01',repeat('a',64),'editor_alpha_01','2026-11-09 10:00:00+00'),
('tenant_beta_01','release_beta_01',repeat('b',64),'editor_beta_01','2026-11-09 10:00:00+00'),
('tenant_gamma_01','release_gamma_01',repeat('c',64),'editor_gamma_01','2026-11-09 10:00:00+00');

INSERT INTO commercial_publishing_lab.approvals
(organization_id,release_id,payload_sha256,editor_actor,approver_actor,approved_at)
VALUES
('tenant_alpha_01','release_alpha_01',repeat('a',64),'editor_alpha_01','reviewer_alpha_01','2026-11-09 10:15:00+00'),
('tenant_beta_01','release_beta_01',repeat('b',64),'editor_beta_01','reviewer_beta_01','2026-11-09 10:20:00+00');

INSERT INTO commercial_publishing_lab.publish_outbox
(organization_id,release_id,payload_sha256,publisher_actor,queued_at,idempotency_key)
VALUES
('tenant_alpha_01','release_alpha_01',repeat('a',64),'publisher_alpha_01','2026-11-09 10:30:00+00','publish_alpha_01');

-- As restrições e triggers precisam rejeitar vários cenários adversariais.
DO $verify$
DECLARE denied boolean;
BEGIN
  denied:=false;
  BEGIN
    INSERT INTO commercial_publishing_lab.approvals
      (organization_id,release_id,payload_sha256,editor_actor,approver_actor,approved_at)
    VALUES ('tenant_alpha_01','release_beta_01',repeat('b',64),'editor_beta_01',
      'reviewer_alpha_01','2026-11-09 10:22:00+00');
  EXCEPTION WHEN foreign_key_violation THEN denied:=true; END;
  IF NOT denied THEN RAISE EXCEPTION 'cross-tenant approval accepted'; END IF;

  denied:=false;
  BEGIN
    INSERT INTO commercial_publishing_lab.approvals
      (organization_id,release_id,payload_sha256,editor_actor,approver_actor,approved_at)
    VALUES ('tenant_gamma_01','release_gamma_01',repeat('c',64),'editor_gamma_01',
      'editor_gamma_01','2026-11-09 10:22:00+00');
  EXCEPTION WHEN check_violation THEN denied:=true; END;
  IF NOT denied THEN RAISE EXCEPTION 'self-approval accepted'; END IF;

  denied:=false;
  BEGIN
    INSERT INTO commercial_publishing_lab.approvals
      (organization_id,release_id,payload_sha256,editor_actor,approver_actor,approved_at)
    VALUES ('tenant_gamma_01','release_gamma_01',repeat('c',64),'editor_gamma_01',
      'reviewer_gamma_01','2026-11-09 09:59:00+00');
  EXCEPTION WHEN raise_exception THEN denied:=true; END;
  IF NOT denied THEN RAISE EXCEPTION 'approval before submission accepted'; END IF;

  denied:=false;
  BEGIN
    INSERT INTO commercial_publishing_lab.publish_outbox
      (organization_id,release_id,payload_sha256,publisher_actor,queued_at,idempotency_key)
    VALUES ('tenant_gamma_01','release_gamma_01',repeat('c',64),
      'publisher_gamma_01','2026-11-09 10:25:00+00','publish_gamma_01');
  EXCEPTION WHEN foreign_key_violation THEN denied:=true; END;
  IF NOT denied THEN RAISE EXCEPTION 'unapproved content entered outbox'; END IF;

  denied:=false;
  BEGIN
    INSERT INTO commercial_publishing_lab.publish_outbox
      (organization_id,release_id,payload_sha256,publisher_actor,queued_at,idempotency_key)
    VALUES ('tenant_alpha_01','release_alpha_01',repeat('a',64),
      'publisher_alpha_01','2026-11-09 10:31:00+00','publish_alpha_02');
  EXCEPTION WHEN unique_violation THEN denied:=true; END;
  IF NOT denied THEN RAISE EXCEPTION 'release replay accepted'; END IF;

  denied:=false;
  BEGIN
    INSERT INTO commercial_publishing_lab.publish_outbox
      (organization_id,release_id,payload_sha256,publisher_actor,queued_at,idempotency_key)
    VALUES ('tenant_beta_01','release_beta_01',repeat('b',64),
      'reviewer_beta_01','2026-11-09 10:31:00+00','publish_beta_01');
  EXCEPTION WHEN raise_exception THEN denied:=true; END;
  IF NOT denied THEN RAISE EXCEPTION 'publisher and reviewer were not segregated'; END IF;

  denied:=false;
  BEGIN
    UPDATE commercial_publishing_lab.releases SET payload_sha256=repeat('f',64)
    WHERE organization_id='tenant_alpha_01' AND release_id='release_alpha_01';
  EXCEPTION WHEN raise_exception THEN denied:=true; END;
  IF NOT denied THEN RAISE EXCEPTION 'approved payload rewrite accepted'; END IF;

  denied:=false;
  BEGIN
    DELETE FROM commercial_publishing_lab.publish_outbox
    WHERE organization_id='tenant_alpha_01';
  EXCEPTION WHEN raise_exception THEN denied:=true; END;
  IF NOT denied THEN RAISE EXCEPTION 'outbox history deletion accepted'; END IF;

  IF (SELECT count(*) FROM commercial_publishing_lab.publish_outbox) <> 1 THEN
    RAISE EXCEPTION 'unexpected number of queued synthetic releases';
  END IF;
  RAISE NOTICE 'PostgreSQL F5: foreign tenant, self approval, chronology, unapproved enqueue, replay, separation and history rewrite rejected.';
END $verify$;

-- Security boundary test: READ ONLY role cannot query another tenant under
-- transaction-local organization context. This setting itself is NOT identity.
BEGIN;
SET LOCAL ROLE commercial_lab_reader;
SET LOCAL commercial_lab.organization_id='tenant_alpha_01';
DO $rls$
DECLARE count_releases integer; count_approvals integer; count_outbox integer; denied boolean:=false;
BEGIN
  SELECT count(*) INTO count_releases FROM commercial_publishing_lab.releases;
  SELECT count(*) INTO count_approvals FROM commercial_publishing_lab.approvals;
  SELECT count(*) INTO count_outbox FROM commercial_publishing_lab.publish_outbox;
  IF count_releases<>1 OR count_approvals<>1 OR count_outbox<>1 THEN
    RAISE EXCEPTION 'tenant A read scope leak: % % %',count_releases,count_approvals,count_outbox;
  END IF;
  BEGIN
    DELETE FROM commercial_publishing_lab.releases WHERE organization_id='tenant_beta_01';
  EXCEPTION WHEN insufficient_privilege THEN denied:=true; END;
  IF NOT denied THEN RAISE EXCEPTION 'reader role improperly modified ledger'; END IF;
END $rls$;
COMMIT;

-- Scope must be absent outside its transaction, even when the same role
-- connection is reused.
BEGIN;
SET LOCAL ROLE commercial_lab_reader;
DO $scope$
BEGIN
  IF current_setting('commercial_lab.organization_id',true) IS NOT NULL THEN
    RAISE EXCEPTION 'tenant context leaked across transactions';
  END IF;
  IF (SELECT count(*) FROM commercial_publishing_lab.releases)<>0 THEN
    RAISE EXCEPTION 'RLS unexpectedly allowed reads without tenant';
  END IF;
END $scope$;
COMMIT;

BEGIN;
SET LOCAL ROLE commercial_lab_reader;
SET LOCAL commercial_lab.organization_id='tenant_beta_01';
DO $beta$
BEGIN
  IF (SELECT count(*) FROM commercial_publishing_lab.releases)<>1 OR
     (SELECT count(*) FROM commercial_publishing_lab.publish_outbox)<>0 THEN
    RAISE EXCEPTION 'tenant B read scope leaked tenant A data';
  END IF;
END $beta$;
COMMIT;

SELECT 'PASS — isolated PostgreSQL release/approval/outbox ledger with forced RLS and adversarial checks' AS lab_result;
