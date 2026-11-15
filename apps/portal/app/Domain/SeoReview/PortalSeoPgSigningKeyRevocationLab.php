<?php
declare(strict_types=1);

namespace App\Domain\SeoReview;

use PDO;

/**
 * F128: revoke one server-selected signing key under the SAME cooperative
 * tenant+signer transaction lock used by F127 report verification/acceptance.
 *
 * Deliberately NOT an admin endpoint or issuer-auth service; no audit event,
 * dual control, key custody or production approval is supplied.
 */
final class PortalSeoPgSigningKeyRevocationLab
{
    public function __construct(private readonly PDO $pdo) {}

    public function revoke(
        mixed $trustedContext,string $trustedSignerRef,
        string $trustedFingerprint,int $trustedNow
    ):?array {
        if(PHP_SAPI!=='cli'||
           getenv('CI')!=='true'||
           getenv('APP_ENV')!=='testing'||
           getenv('PORTAL_SEO_F128_PG_CI')!=='1'||
           getenv('DB_CONNECTION')!=='pgsql'||
           getenv('DB_DATABASE')!=='portal_gsc_f108_ci'||
           !preg_match('/\A[a-z0-9][a-z0-9_-]{1,79}\z/D',
               $trustedSignerRef)||
           !preg_match('/\A[a-f0-9]{64}\z/D',$trustedFingerprint)||
           $trustedNow<1700000000||$trustedNow>4102444800)
            return null;

        $scope=new GoogleSearchConsoleTenantScopedTransaction($this->pdo);
        return $scope->run($trustedContext,
            static function(PDO $db)use(
                $trustedContext,$trustedSignerRef,$trustedFingerprint,
                $trustedNow
            ):?array {
                if(!PortalSeoPgSigningKeyLock::acquire(
                    $db,$trustedContext['tenantRef'],$trustedSignerRef
                ))return null;

                $stmt=$db->prepare(
                    "UPDATE portal_seo_build_signing_keys
                     SET key_status='revoked',revoked_at=:now
                     WHERE tenant_ref=:tenant AND signer_ref=:signer
                       AND public_key_fingerprint=:fingerprint
                       AND key_status='active' AND revoked_at IS NULL
                       AND not_before<=:valid_from
                     RETURNING public_key_fingerprint"
                );
                $stmt->execute([
                    'now'=>$trustedNow,
                    'tenant'=>$trustedContext['tenantRef'],
                    'signer'=>$trustedSignerRef,
                    'fingerprint'=>$trustedFingerprint,
                    'valid_from'=>$trustedNow,
                ]);
                if($stmt->fetchColumn()===false)return null;
                return [
                    'contractVersion'=>
                        'portal-seo-f128-cooperative-signer-revocation-lab-v1',
                    'oneSignerKeyRevokedInPgLab'=>true,
                    'tenantSignerTransactionLockUsed'=>true,
                    'revocationAuditPersisted'=>false,
                    'unauthorizedOutOfBandMutationExcluded'=>false,
                    'realAdministratorVerified'=>false,
                    'realKmsRevocationVerified'=>false,
                    'customerDisplayAuthorized'=>false,
                    'publicationAuthorized'=>false,
                    'releaseStatus'=>
                        'HOLD_KEY_GOVERNANCE_AUDIT_ADMIN_IDENTITY_AND_STAGING',
                ];
            }
        );
    }

    public static function authorizesProductionKeyRevocation():bool{return false;}
    public static function authorizesCustomerDisplay():bool{return false;}
}
