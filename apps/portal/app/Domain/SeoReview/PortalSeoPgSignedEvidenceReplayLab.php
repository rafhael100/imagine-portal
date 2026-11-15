<?php
declare(strict_types=1);

namespace App\Domain\SeoReview;

use PDO;

/**
 * F123: atomic one-use consumption of an already verified F122 signature in
 * a real disposable PostgreSQL 18 database with FORCE tenant RLS.
 *
 * The caller's identity, public key and tenant are CI-synthetic. This class
 * is NOT wired to HTTP, cannot authorize a real customer, or publish a page.
 */
final class PortalSeoPgSignedEvidenceReplayLab
{
    public function __construct(private readonly PDO $pdo) {}

    public function consume(
        mixed $envelope,
        string $trustedPublicKey,
        mixed $trustedContext,
        string $trustedRunRef,
        string $expectedEngineCommit,
        int $trustedNow,
    ): ?array {
        if(PHP_SAPI!=='cli' ||
           getenv('CI')!=='true' ||
           getenv('APP_ENV')!=='testing' ||
           getenv('PORTAL_SEO_F118_LAB')!=='1' ||
           getenv('PORTAL_SEO_F122_LAB')!=='1' ||
           getenv('PORTAL_SEO_F123_PG_CI')!=='1' ||
           getenv('DB_CONNECTION')!=='pgsql' ||
           getenv('DB_DATABASE')!=='portal_gsc_f108_ci' ||
           !is_array($trustedContext) ||
           array_is_list($trustedContext) ||
           count($trustedContext)!==2 ||
           !isset($trustedContext['tenantRef'],$trustedContext['actorRef']) ||
           !is_string($trustedContext['tenantRef']) ||
           !is_string($trustedContext['actorRef']))
            return null;

        $lab=new PortalSeoSignedBuildEvidenceLab();
        $verified=$lab->review(
            $envelope,$trustedPublicKey,
            $trustedContext['tenantRef'],$trustedRunRef,
            $expectedEngineCommit,$trustedNow
        );
        if($verified===null)return null;

        // The signature has already authenticated the exact payload bytes
        // against the caller's trust anchor and expected tenant/run/commit.
        // This extraction remains defensive; never accept an unsigned nonce.
        try{
            $raw=base64_decode($envelope['payloadBase64'],true);
            if(!is_string($raw))return null;
            $claims=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
            if(!is_array($claims) ||
               !is_string($claims['nonce']??null) ||
               !is_int($claims['expiresAt']??null))
                return null;
        }catch(\Throwable){
            return null;
        }

        $nonceDigest=hash('sha256',
            "IMAGINE-SEO-F123-NONCE\n".
            $trustedContext['tenantRef']."\n".$claims['nonce']);
        $keyFingerprint=hash('sha256',$trustedPublicKey);
        $scope=new GoogleSearchConsoleTenantScopedTransaction($this->pdo);

        $consumed=$scope->run($trustedContext,
            static function(PDO $db)use(
                $trustedContext,$trustedRunRef,$expectedEngineCommit,
                $trustedNow,$nonceDigest,$keyFingerprint,$claims
            ):bool {
                $stmt=$db->prepare(
                    'INSERT INTO portal_seo_build_evidence_nonces
                    (tenant_ref,nonce_digest,public_key_fingerprint,
                     run_ref,engine_commit_sha,received_at,expires_at)
                    VALUES
                    (:tenant,:digest,:fingerprint,:run,:engine,:received,:expires)
                    ON CONFLICT (tenant_ref,nonce_digest) DO NOTHING
                    RETURNING nonce_digest'
                );
                if(!$stmt)return false;
                $stmt->execute([
                    'tenant'=>$trustedContext['tenantRef'],
                    'digest'=>$nonceDigest,
                    'fingerprint'=>$keyFingerprint,
                    'run'=>$trustedRunRef,
                    'engine'=>$expectedEngineCommit,
                    'received'=>$trustedNow,
                    'expires'=>$claims['expiresAt'],
                ]);
                return $stmt->fetchColumn()!==false;
            }
        );
        if($consumed!==true)return null;

        return [
            'contractVersion'=>'portal-seo-f123-once-signed-build-evidence-pg18-lab-v1',
            'sanitizedBuildReview'=>$verified['report'],
            'signatureVerifiedInLab'=>true,
            'nonceConsumedAtomicallyInPgLab'=>true,
            'tenantRlsScopeUsedInLab'=>true,
            'realServiceSignerVerified'=>false,
            'realPortalTenantVerified'=>false,
            'realReplayLedgerDeployed'=>false,
            'customerDisplayAuthorized'=>false,
            'publicationAuthorized'=>false,
            'networkPerformed'=>false,
            'releaseStatus'=>'HOLD_REAL_SIGNER_PORTAL_IDENTITY_KMS_AND_STAGING',
        ];
    }

    public static function authorizesCustomerDisplay():bool{return false;}
    public static function authorizesProductionPublication():bool{return false;}
}
