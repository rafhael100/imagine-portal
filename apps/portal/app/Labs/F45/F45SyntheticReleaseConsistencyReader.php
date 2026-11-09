<?php

declare(strict_types=1);

namespace App\Labs\F45;

use App\Domain\SiteIntake\SiteContentCandidate;
use PDO;
use Throwable;

/**
 * F45: tenant-specific READ ONLY verification of editorial fixture state.
 *
 * Checks that a fake approval receipt references EXACTLY the candidate
 * currently stored, its hash, decision and revision. Real-site publishing
 * remains categorically disabled. The reader cannot prove the authenticity
 * of DB rows, the author of an approval or any kind of client consent.
 */
final readonly class F45SyntheticReleaseConsistencyReader
{
    public function __construct(private PDO $pdo)
    {
        if (getenv('F22_ENABLE_LAB')!=='NON_OPERATIONAL_F22'
            || getenv('F44_ENABLE_LAB')!=='SYNTHETIC_SITES_REVIEW_ONLY'
            || getenv('F45_ENABLE_LAB')!=='READ_ONLY_SITES_CONSISTENCY'
            || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='pgsql') {
            throw new \LogicException('F45 only runs in a disposable PostgreSQL 18 test fixture.');
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES,false);
    }

    public function check(SiteContentCandidate $candidate): F45EditorialConsistencyResult
    {
        $organization=$candidate->brief->organizationId->value;
        if (!in_array($organization,['org-a','org-b'],true)) {
            return new F45EditorialConsistencyResult('missing_demo_candidate');
        }
        if ($this->pdo->inTransaction()) {
            return new F45EditorialConsistencyResult('source_unavailable');
        }

        try {
            $this->pdo->beginTransaction();
            $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            $this->pdo->exec("SET LOCAL statement_timeout='2000ms'");
            $expectedRole=$organization==='org-a'
                ? 'f45_consistency_reader_a' : 'f45_consistency_reader_b';
            $role=$this->pdo->query(
                "SELECT session_user AS login_role, current_user AS active_role,
                       (SELECT NOT rolsuper AND NOT rolbypassrls
                                   AND NOT rolinherit AND rolcanlogin
                          FROM pg_catalog.pg_roles WHERE rolname=current_user)
                        AS restricted"
            )->fetch(PDO::FETCH_ASSOC);
            if (!is_array($role)
                || ($role['login_role'] ?? null)!==$expectedRole
                || ($role['active_role'] ?? null)!==$expectedRole
                || !$this->pgTrue($role['restricted'] ?? null)) {
                return $this->deny('untrusted_sql_role');
            }

            $relations=$this->pdo->query(
                "SELECT c.relname,c.relrowsecurity AS rls_on,
                        c.relforcerowsecurity AS rls_force,
                        (SELECT count(*)::int FROM pg_catalog.pg_policy p
                          WHERE p.polrelid=c.oid) AS policy_count
                   FROM pg_catalog.pg_class c
                   JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace
                  WHERE n.nspname='f44' AND c.relkind='r'
                    AND c.relname IN ('site_review','review_receipt')"
            )->fetchAll(PDO::FETCH_ASSOC);
            if (count($relations)!==2) {
                return $this->deny('unsafe_synthetic_schema');
            }
            $seen=[];
            foreach ($relations as $relation) {
                $name=$relation['relname'] ?? null;
                if (!in_array($name,['site_review','review_receipt'],true)
                    || isset($seen[$name])
                    || !$this->pgTrue($relation['rls_on'] ?? null)
                    || !$this->pgTrue($relation['rls_force'] ?? null)
                    || (int)($relation['policy_count'] ?? -1)!==4) {
                    return $this->deny('unsafe_synthetic_schema');
                }
                $seen[$name]=true;
            }

            $stmt=$this->pdo->prepare(
                'SELECT organization_id,site_reference,content_sha256,
                        brief_revision,content_revision,review_status,review_revision
                   FROM f44.site_review
                  WHERE organization_id=:org AND site_reference=:site
                  LIMIT 2'
            );
            $stmt->execute([
                'org'=>$organization,
                'site'=>$candidate->brief->reference,
            ]);
            $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows)!==1) {
                return $this->deny('missing_demo_candidate');
            }
            $row=$rows[0];
            if ($row['organization_id']!==$organization
                || $row['site_reference']!==$candidate->brief->reference
                || !hash_equals((string)$row['content_sha256'],$candidate->fingerprint)
                || (int)$row['brief_revision']!==$candidate->brief->revision
                || (int)$row['content_revision']!==$candidate->contentRevision) {
                return $this->deny('content_changed');
            }
            if ($row['review_status']!=='approved_demo') {
                return $this->deny('not_approved');
            }

            // Read the COMPLETE bounded receipt set for that demo site,
            // not just the newest, to detect ambiguous/conflicting state.
            $receipts=$this->pdo->prepare(
                'SELECT site_reference,content_sha256,simulated_decision,
                        resulting_status,resulting_review_revision
                   FROM f44.review_receipt
                  WHERE organization_id=:org AND site_reference=:site
                  ORDER BY command_id LIMIT 11'
            );
            $receipts->execute([
                'org'=>$organization,
                'site'=>$candidate->brief->reference,
            ]);
            $all=$receipts->fetchAll(PDO::FETCH_ASSOC);
            if (count($all)>10) {
                return $this->deny('ambiguous_receipts');
            }
            if ($all===[]) {
                return $this->deny('missing_receipt');
            }

            $matches=0;
            foreach ($all as $receipt) {
                // Historical receipts for older content versions cannot
                // validate a newer version. Ignore those old hashes.
                if (!hash_equals((string)$receipt['content_sha256'],$candidate->fingerprint)) {
                    continue;
                }
                if ($receipt['site_reference']!==$candidate->brief->reference
                    || $receipt['simulated_decision']!=='approve'
                    || $receipt['resulting_status']!=='approved_demo'
                    || (int)$receipt['resulting_review_revision']!==(int)$row['review_revision']) {
                    return $this->deny('receipt_mismatch');
                }
                $matches++;
            }
            if ($matches===0) {
                return $this->deny('missing_receipt');
            }
            if ($matches!==1 || (int)$row['review_revision']<1) {
                return $this->deny('ambiguous_receipts');
            }

            $this->pdo->commit();
            return new F45EditorialConsistencyResult('consistent_approved_demo');
        } catch (Throwable) {
            try {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
            } catch (Throwable) {
                // Fail closed, no privileged fallback.
            }
            return new F45EditorialConsistencyResult('source_unavailable');
        }
    }

    private function deny(string $reason): F45EditorialConsistencyResult
    {
        $this->pdo->rollBack();
        return new F45EditorialConsistencyResult($reason);
    }

    private function pgTrue(mixed $value): bool
    {
        return in_array($value,[true,'t','true',1,'1'],true);
    }
}
