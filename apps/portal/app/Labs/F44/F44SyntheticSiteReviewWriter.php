<?php

declare(strict_types=1);

namespace App\Labs\F44;

use App\Domain\SiteIntake\SiteContentCandidate;
use App\Domain\SiteIntake\SiteReviewCommand;
use App\Domain\SiteIntake\SiteReviewDecision;
use PDO;
use Throwable;

/**
 * F44: transactional FAKE editorial decision against tenant-isolated PG18.
 *
 * All actor, company and command values remain caller-supplied fixtures.
 * Does NOT authenticate a customer or authorize publication, billing,
 * contract activation or an API route. Never deploy this lab adapter.
 */
final readonly class F44SyntheticSiteReviewWriter
{
    public function __construct(private PDO $pdo)
    {
        if (getenv('F22_ENABLE_LAB')!=='NON_OPERATIONAL_F22'
            || getenv('F44_ENABLE_LAB')!=='SYNTHETIC_SITES_REVIEW_ONLY'
            || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='pgsql') {
            throw new \LogicException('F44 requires disposable PostgreSQL 18.');
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES,false);
    }

    public function apply(
        SiteContentCandidate $candidate,
        SiteReviewCommand $command,
    ): F44SyntheticReviewWriteResult {
        if (!$candidate->brief->organizationId->equals($command->organization)) {
            return F44SyntheticReviewWriteResult::denied('organization_mismatch');
        }
        if ($candidate->brief->reference!==$command->siteReference) {
            return F44SyntheticReviewWriteResult::denied('reference_mismatch');
        }
        if (!hash_equals($candidate->fingerprint,$command->contentFingerprint)) {
            return F44SyntheticReviewWriteResult::denied('content_changed');
        }
        $organization=$command->organization->value;
        if (!in_array($organization,['org-a','org-b'],true)) {
            return F44SyntheticReviewWriteResult::denied('organization_mismatch');
        }
        $expectedActor=$organization==='org-a'
            ? 'DEMO-REVIEWER-A' : 'DEMO-REVIEWER-B';
        if ($command->actor!==$expectedActor) {
            return F44SyntheticReviewWriteResult::denied('untrusted_demo_actor');
        }
        if ($this->pdo->inTransaction()) {
            return F44SyntheticReviewWriteResult::denied('source_unavailable');
        }

        try {
            $this->pdo->beginTransaction();
            $this->pdo->exec("SET LOCAL statement_timeout='2000ms'");
            $this->pdo->exec("SET LOCAL lock_timeout='400ms'");

            $expectedRole=$organization==='org-a'
                ? 'f44_reviewer_a' : 'f44_reviewer_b';
            $identity=$this->pdo->query(
                "SELECT session_user AS login_role,current_user AS active_role,
                        (SELECT NOT rolsuper AND NOT rolbypassrls
                                    AND NOT rolinherit AND rolcanlogin
                           FROM pg_catalog.pg_roles WHERE rolname=current_user)
                         AS restricted"
            )->fetch(PDO::FETCH_ASSOC);
            if (!is_array($identity)
                || $identity['login_role']!==$expectedRole
                || $identity['active_role']!==$expectedRole
                || !$this->pgTrue($identity['restricted'] ?? null)) {
                $this->pdo->rollBack();
                return F44SyntheticReviewWriteResult::denied('untrusted_sql_role');
            }

            $meta=$this->pdo->query(
                "SELECT c.relname,c.relrowsecurity AS enabled,
                        c.relforcerowsecurity AS forced,
                        (SELECT count(*)::int FROM pg_catalog.pg_policy p
                          WHERE p.polrelid=c.oid) AS policy_count
                   FROM pg_catalog.pg_class c
                   JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace
                  WHERE n.nspname='f44'
                    AND c.relname IN ('site_review','review_receipt')
                    AND c.relkind='r'"
            )->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($meta) || count($meta)!==2) {
                $this->pdo->rollBack();
                return F44SyntheticReviewWriteResult::denied('unsafe_synthetic_schema');
            }
            // F45 adds two explicit SELECT-only policies for new reader
            // roles. Preserve drift denial: only 2 (F44-only fixture)
            // or exactly 4 (when F45 lab is explicitly provisioned).
            $expectedPolicies = getenv('F45_ENABLE_LAB')==='READ_ONLY_SITES_CONSISTENCY'
                ? 4 : 2;
            $seen=[];
            foreach ($meta as $object) {
                $name=$object['relname'] ?? null;
                if (!in_array($name,['site_review','review_receipt'],true)
                    || isset($seen[$name])
                    || !$this->pgTrue($object['enabled'] ?? null)
                    || !$this->pgTrue($object['forced'] ?? null)
                    || (int)($object['policy_count'] ?? -1)!==$expectedPolicies) {
                    $this->pdo->rollBack();
                    return F44SyntheticReviewWriteResult::denied('unsafe_synthetic_schema');
                }
                $seen[$name]=true;
            }

            // Serialize competing reviews for same fictitious site.
            $stmt=$this->pdo->prepare(
                'SELECT content_sha256,brief_revision,content_revision,
                        review_status,review_revision
                   FROM f44.site_review
                  WHERE organization_id=:org AND site_reference=:site
                  FOR UPDATE'
            );
            $stmt->execute([
                'org'=>$organization,
                'site'=>$command->siteReference,
            ]);
            $record=$stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($record)) {
                $this->pdo->rollBack();
                return F44SyntheticReviewWriteResult::denied('missing_demo_candidate');
            }

            // Validate the CURRENT database candidate before looking up
            // idempotency receipts; old approvals cannot be replayed after
            // a replacement revision, even with an old command ID.
            if (!hash_equals((string)$record['content_sha256'],$candidate->fingerprint)
                || (int)$record['brief_revision']!==$candidate->brief->revision
                || (int)$record['content_revision']!==$candidate->contentRevision) {
                $this->pdo->rollBack();
                return F44SyntheticReviewWriteResult::denied('content_changed');
            }

            $receipt=$this->pdo->prepare(
                'SELECT command_sha256,content_sha256,site_reference,
                        resulting_status,resulting_review_revision
                   FROM f44.review_receipt
                  WHERE organization_id=:org AND command_id=:command
                  LIMIT 2'
            );
            $receipt->execute(['org'=>$organization,'command'=>$command->commandId]);
            $receipts=$receipt->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($receipts) || count($receipts)>1) {
                $this->pdo->rollBack();
                return F44SyntheticReviewWriteResult::denied('unsafe_synthetic_schema');
            }
            if ($receipts!==[]) {
                $old=$receipts[0];
                if ($old['site_reference']!==$command->siteReference
                    || !hash_equals((string)$old['content_sha256'],$candidate->fingerprint)
                    || !hash_equals((string)$old['command_sha256'],$command->fingerprint())) {
                    $this->pdo->rollBack();
                    return F44SyntheticReviewWriteResult::denied('idempotency_conflict');
                }
                if (!in_array($old['resulting_status'],[
                    'approved_demo','changes_requested_demo','rejected_demo',
                ],true)) {
                    $this->pdo->rollBack();
                    return F44SyntheticReviewWriteResult::denied('unsafe_synthetic_schema');
                }
                $this->pdo->commit();
                return new F44SyntheticReviewWriteResult(
                    'replayed_synthetic_sql',
                    $old['resulting_status'],
                    (int)$old['resulting_review_revision'],
                );
            }

            if ((int)$record['review_revision']!==$command->expectedReviewRevision) {
                $this->pdo->rollBack();
                return F44SyntheticReviewWriteResult::denied('stale_review_revision');
            }
            if ($record['review_status']!=='awaiting_review_demo') {
                $this->pdo->rollBack();
                return F44SyntheticReviewWriteResult::denied('review_already_completed');
            }

            $status=match($command->decision) {
                SiteReviewDecision::Approve=>'approved_demo',
                SiteReviewDecision::RequestChanges=>'changes_requested_demo',
                SiteReviewDecision::Reject=>'rejected_demo',
            };
            $newRevision=$command->expectedReviewRevision+1;
            $updated=$this->pdo->prepare(
                "UPDATE f44.site_review
                    SET review_status=:status,review_revision=:next_revision
                  WHERE organization_id=:org AND site_reference=:site
                    AND content_sha256=:fingerprint
                    AND review_status='awaiting_review_demo'
                    AND review_revision=:expected_revision
                  RETURNING review_revision"
            );
            $updated->execute([
                'status'=>$status,
                'next_revision'=>$newRevision,
                'org'=>$organization,
                'site'=>$command->siteReference,
                'fingerprint'=>$candidate->fingerprint,
                'expected_revision'=>$command->expectedReviewRevision,
            ]);
            $rows=$updated->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows)!==1 || (int)$rows[0]['review_revision']!==$newRevision) {
                $this->pdo->rollBack();
                return F44SyntheticReviewWriteResult::denied('stale_review_revision');
            }

            $insert=$this->pdo->prepare(
                'INSERT INTO f44.review_receipt(
                    organization_id,site_reference,command_id,command_sha256,
                    content_sha256,simulated_decision,resulting_status,
                    resulting_review_revision,occurred_at
                 ) VALUES (
                    :org,:site,:command,:command_sha,:content_sha,:decision,
                    :status,:revision,:at
                 )'
            );
            $insert->execute([
                'org'=>$organization,
                'site'=>$command->siteReference,
                'command'=>$command->commandId,
                'command_sha'=>$command->fingerprint(),
                'content_sha'=>$candidate->fingerprint,
                'decision'=>$command->decision->value,
                'status'=>$status,
                'revision'=>$newRevision,
                'at'=>$command->occurredAt,
            ]);
            $this->pdo->commit();
            return new F44SyntheticReviewWriteResult(
                'applied_synthetic_sql',$status,$newRevision,
            );
        } catch (Throwable) {
            try {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
            } catch (Throwable) {
                // No fallback to privileged SQL roles on connection failure.
            }
            return F44SyntheticReviewWriteResult::denied('source_unavailable');
        }
    }

    private function pgTrue(mixed $value): bool
    {
        return in_array($value,[true,'t','true',1,'1'],true);
    }
}
