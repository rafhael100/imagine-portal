<?php
declare(strict_types=1);

namespace App\Domain\SeoReview;

use PDO;

/**
 * SEO F139 — consume an already VERIFIED multilingual Engine F126 report
 * exactly once in a disposable PG18 database with enforced tenant RLS.
 * CI ONLY; never authorizes customer data, publication or Google access.
 */
final class PortalSeoPgMultilingualEvidenceOnceLab
{
    public function __construct(private readonly PDO $pdo) {}

    public function consume(
        mixed $envelope,
        string $trustedPublicKey,
        mixed $trustedContext,
        string $trustedRunRef,
        string $expectedEngineCommit,
        int $trustedNow
    ): ?array {
        if (PHP_SAPI!=='cli' ||
            getenv('CI')!=='true' ||
            getenv('APP_ENV')!=='testing' ||
            getenv('PORTAL_SEO_F138_LAB')!=='1' ||
            getenv('PORTAL_SEO_F139_PG_CI')!=='1' ||
            getenv('DB_CONNECTION')!=='pgsql' ||
            getenv('DB_DATABASE')!=='portal_gsc_f108_ci' ||
            !is_array($trustedContext) ||
            array_is_list($trustedContext) ||
            count($trustedContext)!==2 ||
            !isset($trustedContext['tenantRef'],$trustedContext['actorRef']) ||
            !is_string($trustedContext['tenantRef']) ||
            !is_string($trustedContext['actorRef']))
            return null;

        $verified=(new PortalSeoSignedMultilingualV2Lab())->review(
            $envelope,$trustedPublicKey,$trustedContext['tenantRef'],
            $trustedRunRef,$expectedEngineCommit,$trustedNow
        );
        if($verified===null)return null;

        // The signature was validated with independently trusted context.
        // Extract only the signed nonce/expiry; never accept an unsigned nonce.
        try {
            $raw=base64_decode($envelope['payloadBase64'],true);
            if(!is_string($raw))return null;
            $payload=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
            if(!is_array($payload) ||
               !is_string($payload['nonce']??null) ||
               !is_int($payload['expiresAt']??null))
                return null;
        }catch(\Throwable){return null;}

        // EXACTLY the same tenant+nonce digest domain as F123/v1, so rotating
        // a key or changing a contract version does not permit reuse.
        $nonceDigest=hash('sha256',
            "IMAGINE-SEO-F123-NONCE\n".
            $trustedContext['tenantRef']."\n".$payload['nonce']
        );
        $publicFingerprint=hash('sha256',$trustedPublicKey);
        $scope=new GoogleSearchConsoleTenantScopedTransaction($this->pdo);
        $consumed=$scope->run($trustedContext,
            static function(PDO $db) use(
                $trustedContext,$trustedRunRef,$expectedEngineCommit,
                $trustedNow,$nonceDigest,$publicFingerprint,$payload
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
                    'fingerprint'=>$publicFingerprint,
                    'run'=>$trustedRunRef,
                    'engine'=>$expectedEngineCommit,
                    'received'=>$trustedNow,
                    'expires'=>$payload['expiresAt']
                ]);
                return $stmt->fetchColumn()!==false;
            }
        );
        if($consumed!==true)return null;

        return [
            'contractVersion'=>'portal-seo-f139-multilingual-once-pg18-lab-v1',
            'reviewStatus'=>$verified['reviewStatus'],
            'summary'=>$verified['summary'],
            'signatureVerifiedInLab'=>true,
            'nonceConsumedAtomicallyInPgLab'=>true,
            'tenantRlsScopeUsedInLab'=>true,
            'productionSignerCustodyVerified'=>false,
            'realPortalTenantAuthenticated'=>false,
            'productionReplayLedgerDeployed'=>false,
            'editorialPublicationAuthorized'=>false,
            'indexingAuthorized'=>false,
            'customerDisplayAuthorized'=>false,
            'releaseStatus'=>'HOLD_REAL_SIGNER_TENANT_STAGING_AND_HUMAN_APPROVAL'
        ];
    }

    public static function allowsProductionPublication():bool{return false;}
    public static function allowsCustomerDisplay():bool{return false;}
}
