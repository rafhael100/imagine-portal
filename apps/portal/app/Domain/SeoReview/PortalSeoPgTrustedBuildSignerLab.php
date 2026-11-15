<?php
declare(strict_types=1);

namespace App\Domain\SeoReview;

use PDO;

/**
 * F127: look up a CI-trusted Ed25519 public key from PostgreSQL FORCE-RLS,
 * then verify and consume the signed Engine report in the SAME transaction.
 *
 * Crucially, no verification key or signer privilege is accepted from the
 * envelope or browser. The known signer_ref is resolved by the trusted
 * synthetic caller. Revocation serialisation is NOT proven in F127; a
 * concurrent administrative revocation still needs a verified lock protocol.
 */
final class PortalSeoPgTrustedBuildSignerLab
{
    private const MAX_ACTIVE_KEYS=4;

    public function __construct(private readonly PDO $pdo) {}

    public function accept(
        mixed $envelope,
        mixed $trustedContext,
        string $trustedSignerRef,
        string $trustedRunRef,
        string $expectedEngineCommit,
        int $trustedNow,
    ): ?array {
        if(PHP_SAPI!=='cli'||
           getenv('CI')!=='true'||
           getenv('APP_ENV')!=='testing'||
           getenv('PORTAL_SEO_F118_LAB')!=='1'||
           getenv('PORTAL_SEO_F122_LAB')!=='1'||
           getenv('PORTAL_SEO_F123_PG_CI')!=='1'||
           getenv('PORTAL_SEO_F127_PG_CI')!=='1'||
           getenv('DB_CONNECTION')!=='pgsql'||
           getenv('DB_DATABASE')!=='portal_gsc_f108_ci'||
           !preg_match('/\A[a-z0-9][a-z0-9_-]{1,79}\z/D',
               $trustedSignerRef))
            return null;

        $scope=new GoogleSearchConsoleTenantScopedTransaction($this->pdo);
        return $scope->run($trustedContext,
            static function(PDO $db)use(
                $envelope,$trustedContext,$trustedSignerRef,
                $trustedRunRef,$expectedEngineCommit,$trustedNow
            ):?array {
                $lookup=$db->prepare(
                    "SELECT public_key_fingerprint,public_key_base64
                     FROM portal_seo_build_signing_keys
                     WHERE tenant_ref=:tenant AND signer_ref=:signer
                       AND key_status='active' AND revoked_at IS NULL
                       AND not_before<=:start AND expires_at>:expiry
                     ORDER BY public_key_fingerprint LIMIT 5"
                );
                $lookup->execute([
                    'tenant'=>$trustedContext['tenantRef'],
                    'signer'=>$trustedSignerRef,
                    'start'=>$trustedNow,
                    'expiry'=>$trustedNow,
                ]);
                $trustedKeys=$lookup->fetchAll(PDO::FETCH_ASSOC);
                if(!is_array($trustedKeys)||$trustedKeys===[]||
                   count($trustedKeys)>self::MAX_ACTIVE_KEYS)
                    return null;

                $lab=new PortalSeoSignedBuildEvidenceLab();
                $verified=null;
                $fingerprint=null;
                foreach($trustedKeys as $row){
                    $encoded=$row['public_key_base64']??null;
                    if(!is_string($encoded)||
                       strlen($encoded)!==44)
                        return null;
                    $key=base64_decode($encoded,true);
                    if($key===false || strlen($key)!==32 ||
                       base64_encode($key)!==$encoded)
                        return null;
                    $fp=hash('sha256',$key);
                    if(!is_string($row['public_key_fingerprint']??null)||
                       !hash_equals($fp,$row['public_key_fingerprint']))
                        return null;
                    $check=$lab->review(
                        $envelope,$key,$trustedContext['tenantRef'],
                        $trustedRunRef,$expectedEngineCommit,$trustedNow
                    );
                    if($check!==null){
                        // Multiple matches would be ambiguous; fail closed.
                        if($verified!==null)return null;
                        $verified=$check;
                        $fingerprint=$fp;
                    }
                }
                if($verified===null || $fingerprint===null)return null;

                // The nonce comes ONLY from a payload which F122 just
                // authenticated against a trusted database public key.
                try{
                    $raw=base64_decode($envelope['payloadBase64'],true);
                    if(!is_string($raw))return null;
                    $claims=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
                    if(!is_array($claims)||
                       !is_string($claims['nonce']??null)||
                       !is_int($claims['expiresAt']??null))
                        return null;
                }catch(\Throwable){
                    return null;
                }
                $digest=hash('sha256',
                    "IMAGINE-SEO-F123-NONCE\n".
                    $trustedContext['tenantRef']."\n".$claims['nonce']);
                $add=$db->prepare(
                    'INSERT INTO portal_seo_build_evidence_nonces
                    (tenant_ref,nonce_digest,public_key_fingerprint,
                     run_ref,engine_commit_sha,received_at,expires_at)
                    VALUES
                    (:tenant,:digest,:fp,:run,:sha,:received,:expires)
                    ON CONFLICT (tenant_ref,nonce_digest) DO NOTHING
                    RETURNING nonce_digest'
                );
                $add->execute([
                    'tenant'=>$trustedContext['tenantRef'],
                    'digest'=>$digest,'fp'=>$fingerprint,
                    'run'=>$trustedRunRef,'sha'=>$expectedEngineCommit,
                    'received'=>$trustedNow,'expires'=>$claims['expiresAt'],
                ]);
                if($add->fetchColumn()===false)return null;

                return [
                    'contractVersion'=>
                        'portal-seo-f127-trusted-key-once-pg18-lab-v1',
                    'sanitizedBuildReview'=>$verified['report'],
                    'signatureVerifiedAgainstDbKeyInLab'=>true,
                    'nonceConsumedInSameTenantTransaction'=>true,
                    'trustedSignerResolvedBySyntheticCaller'=>true,
                    'keyRevocationRaceProven'=>false,
                    'realSignerAuthorityVerified'=>false,
                    'realPortalIdentityVerified'=>false,
                    'realKmsOrKeyDistributionVerified'=>false,
                    'customerDisplayAuthorized'=>false,
                    'publicationAuthorized'=>false,
                    'releaseStatus'=>
                        'HOLD_REAL_SIGNER_KEY_REVOCATION_PORTAL_AND_STAGING',
                ];
            }
        );
    }

    public static function authorizesCustomerDisplay():bool{return false;}
    public static function authorizesProductionPublish():bool{return false;}
}
