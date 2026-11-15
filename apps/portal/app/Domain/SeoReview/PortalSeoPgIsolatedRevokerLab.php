<?php
declare(strict_types=1);

namespace App\Domain\SeoReview;

use PDO;

/**
 * F131: exercise a narrowly-granted PostgreSQL SECURITY DEFINER revoker
 * from a CI caller WITHOUT direct UPDATE on signing keys or INSERT on events.
 *
 * The owner is a synthetic NOLOGIN/NOBYPASSRLS role, and the SQL function
 * refuses all databases other than the disposable PG18 test database.
 * This is NOT a real authenticated Portal admin or a deployment service.
 */
final class PortalSeoPgIsolatedRevokerLab
{
    public function __construct(private readonly PDO $pdo) {}

    public function revoke(
        mixed $trustedContext,
        string $trustedSignerRef,
        string $trustedFingerprint,
        int $trustedNow,
    ):?array {
        if(PHP_SAPI!=='cli' ||
           getenv('CI')!=='true' ||
           getenv('APP_ENV')!=='testing' ||
           getenv('PORTAL_SEO_F131_PG_CI')!=='1' ||
           getenv('DB_CONNECTION')!=='pgsql' ||
           getenv('DB_DATABASE')!=='portal_gsc_f108_ci' ||
           $trustedNow<1700000000 || $trustedNow>4102444800 ||
           preg_match('/\A[a-z0-9][a-z0-9_-]{1,79}\z/D',
               $trustedSignerRef)!==1 ||
           preg_match('/\A[a-f0-9]{64}\z/D',
               $trustedFingerprint)!==1)
            return null;

        $scope=new GoogleSearchConsoleTenantScopedTransaction($this->pdo);
        return $scope->run($trustedContext,
            static function(PDO $db)use(
                $trustedContext,$trustedSignerRef,$trustedFingerprint,
                $trustedNow
            ):?array {
                // The event digest is deterministic only for fixture
                // idempotency. It is NOT an administrative signature.
                $eventDigest=hash('sha256',
                    "IMAGINE-SEO-F129-KEY-REVOKED\n".
                    $trustedContext['tenantRef']."\n".
                    $trustedSignerRef."\n".
                    $trustedFingerprint."\n".
                    $trustedContext['actorRef']."\n".
                    $trustedNow
                );
                // No table DML here: operator has function EXECUTE only.
                $stmt=$db->prepare(
                    'SELECT public.portal_seo_f131_revoke_signer(
                        :tenant,:signer,:fp,:actor,
                        CAST(:revoked_at AS bigint),:digest)'
                );
                if(!$stmt || !$stmt->execute([
                    'tenant'=>$trustedContext['tenantRef'],
                    'signer'=>$trustedSignerRef,
                    'fp'=>$trustedFingerprint,
                    'actor'=>$trustedContext['actorRef'],
                    'revoked_at'=>$trustedNow,
                    'digest'=>$eventDigest,
                ]) || !in_array($stmt->fetchColumn(),[true,'t','1',1],true))
                    return null;

                return [
                    'contractVersion'=>
                        'portal-seo-f131-restricted-db-function-ci-lab-v1',
                    'revocationCommittedWithMandatoryAuditInPgLab'=>true,
                    'operationalUserDirectTableDmlRequired'=>false,
                    'functionOwnerNoLoginNoBypassRls'=>true,
                    'realPortalAdminIdentityVerified'=>false,
                    'realTenantIdentityVerified'=>false,
                    'realAuditRetentionApproved'=>false,
                    'productionFunctionDeployed'=>false,
                    'customerDisplayAuthorized'=>false,
                    'publicationAuthorized'=>false,
                    'releaseStatus'=>
                        'HOLD_REAL_ADMIN_AUTH_AUDIT_GOVERNANCE_AND_STAGING',
                ];
            }
        );
    }

    public static function authorizesProductionRevocation():bool{return false;}
    public static function authorizesCustomerDisplay():bool{return false;}
}
