<?php
declare(strict_types=1);

namespace App\Domain\SitesIntegration;

use PDO;
use RuntimeException;
use Throwable;

/**
 * PostgreSQL 18 READ-ONLY candidate. Requires a separately audited, official
 * approval-event writer and an independently authorized read-only DB role.
 * Not wired to HTTP, routes, containers, migrations or production.
 *
 * One REPEATABLE READ snapshot joins the latest revision to its approval event.
 * Any terminal revocation/supersession for this revision (even if earlier
 * than a forged reapproval) or a later audit event invalidates approval. Reading a
 * head does NOT authenticate the approver or the requesting service.
 */
final class Pg18LandingPageApprovedBriefHeadSourceCandidate
    implements CurrentApprovedLandingPageBriefHeadSource
{
    public function __construct(private readonly PDO $pdo)
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
            throw new RuntimeException('PORTAL_LANDING_PG_HEAD_DENIED');
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    public function readCurrent(string $tenantRef, string $orderRef, string $siteRef): ?ApprovedLandingPageBriefHead
    {
        if ($this->pdo->inTransaction()) {
            throw new RuntimeException('PORTAL_LANDING_PG_HEAD_DENIED');
        }
        try {
            $this->pdo->beginTransaction();
            $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            $statement = $this->pdo->prepare(<<<'SQL'
WITH latest AS (
    SELECT r.*
      FROM portal_landing_candidate.brief_revisions r
     WHERE r.tenant_ref = :tenant
       AND r.order_ref = :order_ref
       AND r.site_ref = :site_ref
     ORDER BY r.source_version DESC
     LIMIT 1
)
SELECT r.tenant_ref, r.order_ref, r.site_ref, r.contract_ref,
       r.contract_revision, r.source_version, r.source_sha256,
       r.approval_ref, r.approval_epoch, r.source_bytes
  FROM latest r
  JOIN portal_landing_candidate.approval_events a
    ON a.tenant_ref = r.tenant_ref AND a.order_ref = r.order_ref
   AND a.site_ref = r.site_ref AND a.source_version = r.source_version
   AND a.approval_epoch = r.approval_epoch
   AND a.approval_ref = r.approval_ref
   AND a.actor_ref = r.approved_actor_ref
   AND a.event_type = 'approved'
 WHERE r.state = 'approved' AND r.revoked_at IS NULL
   AND NOT EXISTS (
       SELECT 1 FROM portal_landing_candidate.approval_events future
        WHERE future.tenant_ref = r.tenant_ref
          AND future.order_ref = r.order_ref
          AND future.site_ref = r.site_ref
          AND future.source_version = r.source_version
          -- A terminal event is irreversible for this revision, even if
          -- an inconsistent writer subsequently inserts an approval. Any
          -- other later event also invalidates the exact approval snapshot.
          AND (future.event_sequence > a.event_sequence
               OR future.event_type IN ('revoked', 'superseded'))
   )
SQL);
            $statement->execute([
                'tenant' => $tenantRef, 'order_ref' => $orderRef, 'site_ref' => $siteRef,
            ]);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) > 1) {
                throw new RuntimeException();
            }
            $head = null;
            if ($rows !== []) {
                $r = $rows[0];
                $head = new ApprovedLandingPageBriefHead(
                    $r['tenant_ref'], $r['order_ref'], $r['site_ref'], $r['contract_ref'],
                    (int)$r['contract_revision'], (int)$r['source_version'],
                    $r['source_sha256'], $r['approval_ref'], (int)$r['approval_epoch'],
                    $r['source_bytes'],
                );
            }
            $this->pdo->rollBack(); // read-only snapshot always closed, never committed
            return $head;
        } catch (Throwable) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw new RuntimeException('PORTAL_LANDING_PG_HEAD_DENIED');
        }
    }
}
