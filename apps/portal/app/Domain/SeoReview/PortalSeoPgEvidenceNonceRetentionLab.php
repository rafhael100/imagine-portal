<?php
declare(strict_types=1);

namespace App\Domain\SeoReview;

use PDO;

/**
 * F124: bounded, tenant-RLS cleanup of expired F123 nonce digests.
 *
 * A nonce is never deleted until MORE THAN one hour after its signed report
 * expiry. This is a disposable PG18 CI-only retention laboratory: not a
 * production scheduler, not an issuer, not a privileged customer endpoint.
 */
final class PortalSeoPgEvidenceNonceRetentionLab
{
    private const GRACE_SECONDS=3600;
    private const MAX_BATCH=500;

    public function __construct(private readonly PDO $pdo) {}

    public function purgeExpired(mixed $trustedContext,int $trustedNow):?array
    {
        if(PHP_SAPI!=='cli'||
           getenv('CI')!=='true'||
           getenv('APP_ENV')!=='testing'||
           getenv('PORTAL_SEO_F123_PG_CI')!=='1'||
           getenv('PORTAL_SEO_F124_PG_CI')!=='1'||
           getenv('DB_CONNECTION')!=='pgsql'||
           getenv('DB_DATABASE')!=='portal_gsc_f108_ci'||
           $trustedNow<1700000000 || $trustedNow>4102444800 ||
           !is_array($trustedContext)||
           array_is_list($trustedContext)||
           count($trustedContext)!==2||
           !isset($trustedContext['tenantRef'],$trustedContext['actorRef'])||
           !is_string($trustedContext['tenantRef'])||
           !is_string($trustedContext['actorRef']))
            return null;

        $cutoff=$trustedNow-self::GRACE_SECONDS;
        $scoped=new GoogleSearchConsoleTenantScopedTransaction($this->pdo);
        return $scoped->run($trustedContext,
            static function(PDO $db)use($trustedContext,$cutoff):array {
                // SELECT FOR UPDATE SKIP LOCKED limits concurrency
                // contention, and the DELETE/RETURNING is one transaction.
                $stmt=$db->prepare(
                    'WITH expired AS (
                        SELECT tenant_ref,nonce_digest
                        FROM portal_seo_build_evidence_nonces
                        WHERE tenant_ref=:tenant AND expires_at<:threshold
                        ORDER BY expires_at,nonce_digest
                        LIMIT 500
                        FOR UPDATE SKIP LOCKED
                    )
                    DELETE FROM portal_seo_build_evidence_nonces AS ledger
                    USING expired
                    WHERE ledger.tenant_ref=expired.tenant_ref
                      AND ledger.nonce_digest=expired.nonce_digest
                    RETURNING ledger.nonce_digest'
                );
                $stmt->execute([
                    'tenant'=>$trustedContext['tenantRef'],
                    'threshold'=>$cutoff,
                ]);
                $deleted=$stmt->fetchAll(PDO::FETCH_COLUMN,0);
                if(count($deleted)>self::MAX_BATCH)
                    throw new \RuntimeException('F124: invalid batch');
                // Never expose the original nonce, hash, tenant or run
                // through the reporting contract.
                return [
                    'contractVersion'=>'portal-seo-f124-rls-nonce-retention-lab-v1',
                    'purgedRows'=>count($deleted),
                    'maximumBatch'=>self::MAX_BATCH,
                    'gracePeriodSeconds'=>self::GRACE_SECONDS,
                    'retentionRunInDisposablePgLab'=>true,
                    'realTenantIdentityVerified'=>false,
                    'operationalSchedulerDeployed'=>false,
                    'retentionPolicyApproved'=>false,
                    'customerDisplayAuthorized'=>false,
                    'publicationAuthorized'=>false,
                    'releaseStatus'=>'HOLD_DBA_LGPD_RETENTION_REPLAY_AND_PRODUCTION_REVIEW',
                ];
            }
        );
    }

    public static function authorizesDeletionInProduction():bool{return false;}
    public static function authorizesCustomerDisplay():bool{return false;}
}
