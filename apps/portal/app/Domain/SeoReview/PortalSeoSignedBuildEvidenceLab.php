<?php
declare(strict_types=1);

namespace App\Domain\SeoReview;

/**
 * F122: verify signed F114/F117 report evidence in an explicitly restricted
 * CI-only laboratory. Only the CALLER supplies the trust anchor, tenant,
 * run identifier, expected immutable Engine commit, and trusted clock.
 *
 * The report and signature are untrusted bytes. A matching Ed25519 signature
 * proves only that a given key signed those bytes. No signing-key ownership,
 * real tenant identity, replay ledger, publisher, OAuth or host is verified.
 */
final class PortalSeoSignedBuildEvidenceLab
{
    private const ENVELOPE=['contractVersion','payloadBase64','signatureBase64'];
    private const CLAIMS=[
        'issuer','audience','tenantRef','runRef','engineCommitSha',
        'issuedAt','expiresAt','nonce','reportSha256','reportBase64',
    ];
    private const PREFIX="IMAGINE-SEO-F122-SIGNED-BUILD-REPORT\n";
    private const ENGINE_SHA_PATTERN='/\A[a-f0-9]{40}\z/D';

    public function review(
        mixed $envelope,
        string $trustedPublicKey,
        string $trustedTenantRef,
        string $trustedRunRef,
        string $expectedEngineCommit,
        int $trustedNow,
    ): ?array {
        if (PHP_SAPI!=='cli' ||
            getenv('CI')!=='true' ||
            getenv('APP_ENV')!=='testing' ||
            getenv('PORTAL_SEO_F118_LAB')!=='1' ||
            getenv('PORTAL_SEO_F122_LAB')!=='1' ||
            !extension_loaded('sodium') ||
            strlen($trustedPublicKey)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ||
            !preg_match('/\A[a-z0-9][a-z0-9_-]{1,79}\z/D',$trustedTenantRef) ||
            !preg_match('/\A[a-zA-Z0-9:_-]{3,100}\z/D',$trustedRunRef) ||
            !preg_match(self::ENGINE_SHA_PATTERN,$expectedEngineCommit) ||
            $trustedNow<1700000000 || $trustedNow>4102444800 ||
            !self::exact($envelope,self::ENVELOPE) ||
            $envelope['contractVersion']!=='seo-f122-signed-build-report-v1')
            return null;

        $raw=self::decodeBase64($envelope['payloadBase64'],140000);
        $signature=self::decodeBase64($envelope['signatureBase64'],64);
        if ($raw===null || $signature===null ||
            strlen($signature)!==SODIUM_CRYPTO_SIGN_BYTES)
            return null;

        try {
            if(!sodium_crypto_sign_verify_detached(
                $signature,self::PREFIX.$raw,$trustedPublicKey
            )) return null;
            $claims=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        if (!self::exact($claims,self::CLAIMS) ||
            $claims['issuer']!=='imagine-seo-engine-ci-lab' ||
            $claims['audience']!=='imagine-portal-ci-lab' ||
            !is_string($claims['tenantRef']) ||
            !hash_equals($trustedTenantRef,$claims['tenantRef']) ||
            !is_string($claims['runRef']) ||
            !hash_equals($trustedRunRef,$claims['runRef']) ||
            !is_string($claims['engineCommitSha']) ||
            !hash_equals($expectedEngineCommit,$claims['engineCommitSha']) ||
            !is_int($claims['issuedAt']) ||
            !is_int($claims['expiresAt']) ||
            $claims['issuedAt']>$trustedNow+30 ||
            $claims['issuedAt']<$trustedNow-600 ||
            $claims['expiresAt']<=$claims['issuedAt'] ||
            $claims['expiresAt']>$claims['issuedAt']+300 ||
            $claims['expiresAt']<$trustedNow ||
            !is_string($claims['nonce']) ||
            !preg_match('/\A[A-Za-z0-9_-]{32}\z/D',$claims['nonce']) ||
            !is_string($claims['reportSha256']) ||
            !preg_match('/\A[a-f0-9]{64}\z/D',$claims['reportSha256']))
            return null;

        $report=self::decodeBase64($claims['reportBase64'],100000);
        if ($report===null || !hash_equals(
            hash('sha256',$report),$claims['reportSha256']
        )) return null;

        try {
            $dto=json_decode($report,true,64,JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }
        $sanitized=(new PortalSeoStaticBuildReadinessLab())->review($dto);
        if ($sanitized===null) return null;

        // Replay is NOT prevented: no persisted nonce ledger exists in F122.
        // The signed tenant/run values are verified only against a trusted
        // *synthetic CI caller*. Never promote this to production authorization.
        return [
            'contractVersion'=>'portal-seo-f122-signed-build-evidence-lab-v1',
            'report'=>$sanitized,
            'ed25519SignatureVerifiedInLab'=>true,
            'tenantBindingVerifiedAgainstSyntheticCaller'=>true,
            'runBindingVerifiedAgainstSyntheticCaller'=>true,
            'expectedEngineCommitMatched'=>true,
            'freshnessWindowChecked'=>true,
            'replayProtectionVerified'=>false,
            'realTenantIdentityVerified'=>false,
            'realSigningAuthorityVerified'=>false,
            'realSiteVerified'=>false,
            'customerDisplayAuthorized'=>false,
            'publicationAuthorized'=>false,
            'networkPerformed'=>false,
            'releaseStatus'=>'HOLD_TRUSTED_SIGNER_REPLAY_LEDGER_TENANT_AND_STAGING',
        ];
    }

    private static function decodeBase64(mixed $v,int $maxDecoded):?string
    {
        if(!is_string($v)||$v===''||
            strlen($v)>4*(int)ceil($maxDecoded/3)+8)
            return null;
        $bytes=base64_decode($v,true);
        if($bytes===false||strlen($bytes)>$maxDecoded||
            base64_encode($bytes)!==$v)
            return null;
        return $bytes;
    }

    private static function exact(mixed $value,array $fields):bool
    {
        if(!is_array($value)||array_is_list($value)||
           count($value)!==count($fields))
            return false;
        $keys=array_keys($value);
        sort($keys);sort($fields);
        return $keys===$fields;
    }

    public static function authorizesCustomerDisplay():bool{return false;}
    public static function authorizesProductionPublish():bool{return false;}
}
