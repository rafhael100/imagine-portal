-- F6: liga hash emitido pelo PHP -> ledger PostgreSQL -> build Astro.
-- Somente ids/atores sintéticos. Variáveis psql validadas pelo passo anterior.
\set ON_ERROR_STOP on

BEGIN;
INSERT INTO commercial_publishing_lab.releases
  (organization_id,release_id,payload_sha256,editor_actor,requested_at)
VALUES
  ('tenant_imagine_01',:'release_id',:'payload_sha256','editor_demo_01','2026-11-09 02:55:00+00');

INSERT INTO commercial_publishing_lab.approvals
  (organization_id,release_id,payload_sha256,editor_actor,approver_actor,approved_at)
VALUES
  ('tenant_imagine_01',:'release_id',:'payload_sha256',
  'editor_demo_01','reviewer_demo_01','2026-11-09 02:57:00+00');

INSERT INTO commercial_publishing_lab.publish_outbox
  (organization_id,release_id,payload_sha256,publisher_actor,queued_at,idempotency_key)
VALUES
  ('tenant_imagine_01',:'release_id',:'payload_sha256',
  'publisher_demo_01','2026-11-09 02:58:00+00','publish_parity_20261109');
COMMIT;

-- Regras de aprovação materializadas por FK composta, triggers e unicidade.
SELECT (
  (SELECT count(*) FROM commercial_publishing_lab.releases
   WHERE organization_id='tenant_imagine_01' AND release_id=:'release_id'
     AND payload_sha256=:'payload_sha256') = 1 AND
  (SELECT count(*) FROM commercial_publishing_lab.approvals
   WHERE organization_id='tenant_imagine_01' AND release_id=:'release_id'
     AND payload_sha256=:'payload_sha256'
     AND approver_actor <> editor_actor) = 1 AND
  (SELECT count(*) FROM commercial_publishing_lab.publish_outbox
   WHERE organization_id='tenant_imagine_01' AND release_id=:'release_id'
     AND payload_sha256=:'payload_sha256'
     AND state='pending') = 1
) AS approved_release_registered \gset
\if :approved_release_registered
  \echo 'F6: PHP-signed payload matches durable synthetic approval and pending outbox.'
\else
  \echo 'F6: approved release missing or mismatched!'
  \quit 3
\endif

-- App-like reader has no write grant and only sees its current organization.
BEGIN;
SET LOCAL ROLE commercial_lab_reader;
SET LOCAL commercial_lab.organization_id='tenant_unrelated_01';
SELECT (count(*) = 0) AS foreign_tenant_hidden
FROM commercial_publishing_lab.publish_outbox \gset
\if :foreign_tenant_hidden
  \echo 'F6: cross-tenant approval/outbox is not exposed through reader RLS.'
\else
  \quit 3
\endif
COMMIT;

BEGIN;
SET LOCAL ROLE commercial_lab_reader;
SET LOCAL commercial_lab.organization_id='tenant_imagine_01';
SELECT (count(*) = 1) AS own_tenant_visible
FROM commercial_publishing_lab.publish_outbox \gset
\if :own_tenant_visible
  \echo 'F6: matching organization sees one pending synthetic publication.'
\else
  \quit 3
\endif
COMMIT;
