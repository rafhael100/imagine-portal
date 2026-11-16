<?php
declare(strict_types=1);

namespace App\Domain\SeoReview;

use PDO;

/**
 * SEO F140 — trusted signer registry + cooperative revocation lock + single
 * use v2 evidence, ALL in one tenant-scoped PG18 transaction.
 * Synthetic CI only; no caller-supplied public key or publication privilege.
 */
final class PortalSeoPgTrustedMultilingualV2Lab
{
    private const MAX_ACTIVE_KEYS=4;
    public function __construct(private readonly PDO $pdo) {}

    public function accept(
        mixed $envelope,
        mixed $trustedContext,
        string $trustedSignerRef,
        string $trustedRunRef,
        string $expectedEngineCommit,
        int $trustedNow
    ): ?array {
        if(PHP_SAPI!=='cli'||
           getenv('CI')!=='true'||
           getenv('APP_ENV')!=='testing'||
           getenv('PORTAL_SEO_F138_LAB')!=='1'||
           getenv('PORTAL_SEO_F140_PG_CI')!=='1'||
           getenv('DB_CONNECTION')!=='pgsql'||
           getenv('DB_DATABASE')!=='portal_gsc_f108_ci'||
           preg_match('/\A[a-z0-9][a-z0-9_-]{1,79}\z/D',
               $trustedSignerRef)!==1)
            return null;

        $scope=new GoogleSearchConsoleTenantScopedTransaction($this->pdo);
        return $scope->run($trustedContext,
            static function(PDO $db)use(
                $envelope,$trustedContext,$trustedSignerRef,
                $trustedRunRef,$expectedEngineCommit,$trustedNow
            ):?array {
                // Acquired before reading keys and held through nonce insert.
                // Trusted revoker F128/F129 MUST follow the SAME lock.
                if(!PortalSeoPgSigningKeyLock::acquire(
                    $db,$trustedContext['tenantRef'],$trustedSignerRef
                ))return null;

                $lookup=$db->prepare(
                    "SELECT public_key_fingerprint,public_key_base64
                     FROM portal_seo_build_signing_keys
                     WHERE tenant_ref=:tenant AND signer_ref=:signer
                       AND key_status='active' AND revoked_at IS NULL
                       AND not_before<=:start AND expires_at>:expiry
                     ORDER BY public_key_fingerprint LIMIT 5"
                );
                if(!$lookup)return null;
                $lookup->execute([
                    'tenant'=>$trustedContext['tenantRef'],
                    'signer'=>$trustedSignerRef,
                    'start'=>$trustedNow,
                    'expiry'=>$trustedNow
                ]);
                $rows=$lookup->fetchAll(PDO::FETCH_ASSOC);
                if(!is_array($rows)||$rows===[]||
                   count($rows)>self::MAX_ACTIVE_KEYS)return null;

                $verified=null;$fingerprint=null;
                $verifier=new PortalSeoSignedMultilingualV2Lab();
                foreach($rows as $row){
                    $encoded=$row['public_key_base64']??null;
                    if(!is_string($encoded)||strlen($encoded)!==44)return null;
                    $key=base64_decode($encoded,true);
                    if($key===false||strlen($key)!==32||
                       base64_encode($key)!==$encoded)return null;
                    $fp=hash('sha256',$key);
                    if(!is_string($row['public_key_fingerprint']??null)||
                       !hash_equals($fp,$row['public_key_fingerprint']))
                        return null;
                    $check=$verifier->review(
                        $envelope,$key,$trustedContext['tenantRef'],
                        $trustedRunRef,$expectedEngineCommit,$trustedNow
                    );
                    if($check!==null){
                        if($verified!==null)return null; // ambiguous identity
                        $verified=$check;$fingerprint=$fp;
                    }
                }
                if($verified===null||$fingerprint===null)return null;

                // Nonce from the already authenticated envelope ONLY.
                try{
                    $raw=base64_decode($envelope['payloadBase64'],true);
                    if(!is_string($raw))return null;
                    $claims=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
                    if(!is_array($claims)||
                       !is_string($claims['nonce']??null)||
                       !is_int($claims['expiresAt']??null))return null;
                }catch(\Throwable){return null;}

                $digest=hash('sha256',
                    "IMAGINE-SEO-F123-NONCE\n".
                    $trustedContext['tenantRef']."\n".$claims['nonce']
                );
                $insert=$db->prepare(
                    'INSERT INTO portal_seo_build_evidence_nonces
                    (tenant_ref,nonce_digest,public_key_fingerprint,
                     run_ref,engine_commit_sha,received_at,expires_at)
                    VALUES (:tenant,:nonce,:fp,:run,:sha,:received,:expires)
                    ON CONFLICT (tenant_ref,nonce_digest) DO NOTHING
                    RETURNING nonce_digest'
                );
                if(!$insert)return null;
                $insert->execute([
                    'tenant'=>$trustedContext['tenantRef'],
                    'nonce'=>$digest,'fp'=>$fingerprint,
                    'run'=>$trustedRunRef,'sha'=>$expectedEngineCommit,
                    'received'=>$trustedNow,'expires'=>$claims['expiresAt']
                ]);
                if($insert->fetchColumn()===false)return null;

                return [
                    'contractVersion'=>'portal-seo-f140-trusted-v2-once-pg18-lab-v1',
                    'reviewStatus'=>$verified['reviewStatus'],
                    'summary'=>$verified['summary'],
                    'signatureVerifiedAgainstDbKeyInLab'=>true,
                    'nonceConsumedInSameRlsTransaction'=>true,
                    'trustedSignerResolvedBySyntheticCaller'=>true,
                    'cooperativeRevocationLockUsed'=>true,
                    'realProductionSignerVerified'=>false,
                    'realTenantIdentityVerified'=>false,
                    'realKmsOrKeyDistributionVerified'=>false,
                    'outOfBandSignerMutationExcluded'=>false,
                    'productionReplayLedgerDeployed'=>false,
                    'publicationAuthorized'=>false,
                    'indexingAuthorized'=>false,
                    'customerDisplayAuthorized'=>false,
                    'releaseStatus'=>'HOLD_KMS_IDENTITY_APPSEC_STAGING_AND_EDITORIAL'
                ];
            }
        );
    }

    public static function allowsProductionPublish():bool{return false;}
    public static function allowsCustomerDisplay():bool{return false;}
}
