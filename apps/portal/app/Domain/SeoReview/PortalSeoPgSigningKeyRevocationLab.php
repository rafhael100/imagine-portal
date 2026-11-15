<?php
declare(strict_types=1);

namespace App\Domain\SeoReview;

use PDO;

/**
 * F128: revoke one server-selected signing key under the SAME cooperative
 * tenant+signer transaction lock used by F127 report verification/acceptance.
 *
 * Deliberately NOT an admin endpoint or issuer-auth service; F129 adds a
 * mandatory tenant-RLS audit event in the same transaction, but no real
 * dual control, event retention approval or production authority.
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

                // F129: audit is MANDATORY in the SAME transaction.
                // Missing INSERT permission, RLS mismatch or a duplicate
                // audit digest must roll back the preceding key UPDATE.
                $eventDigest=hash('sha256',
                    "IMAGINE-SEO-F129-KEY-REVOKED\n".
                    $trustedContext['tenantRef']."\n".
                    $trustedSignerRef."\n".
                    $trustedFingerprint."\n".
                    $trustedContext['actorRef']."\n".
                    $trustedNow
                );
                $event=$db->prepare(
                    "INSERT INTO portal_seo_build_signer_events
                    (tenant_ref,event_digest,signer_ref,public_key_fingerprint,
                     actor_ref,event_code,occurred_at)
                    VALUES
                    (:tenant,:digest,:signer,:fingerprint,:actor,
                     'signer_key_revoked',:occurred)"
                );
                if(!$event||!$event->execute([
                    'tenant'=>$trustedContext['tenantRef'],
                    'digest'=>$eventDigest,'signer'=>$trustedSignerRef,
                    'fingerprint'=>$trustedFingerprint,
                    'actor'=>$trustedContext['actorRef'],
                    'occurred'=>$trustedNow,
                ])||$event->rowCount()!==1)
                    throw new \RuntimeException('F129: mandatory audit unavailable');

                return [
                    'contractVersion'=>
                        'portal-seo-f128-cooperative-signer-revocation-lab-v1',
                    'oneSignerKeyRevokedInPgLab'=>true,
                    'tenantSignerTransactionLockUsed'=>true,
                    'revocationAuditPersistedInPgLab'=>true,
                    'realAuditRetentionApproved'=>false,
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
