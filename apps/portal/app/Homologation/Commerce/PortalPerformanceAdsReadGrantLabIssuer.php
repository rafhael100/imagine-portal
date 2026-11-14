<?php
declare(strict_types=1);

namespace App\Homologation\Commerce;

use InvalidArgumentException;
use RuntimeException;

/**
 * Candidate S2S authorization envelope for ChatGPT Ads READ-ONLY collection.
 *
 * CI-ONLY. This issuer does NOT authenticate a real Portal user or verify
 * consent/advertiser ownership; all inputs are supplied by synthetic tests.
 * Sign with a separate HMAC key from pricing V6 (domain separation).
 */
final class PortalPerformanceAdsReadGrantLabIssuer
{
    /** @return array<string,mixed> */
    public static function issue(
        string $tenant,
        string $agreement,
        string $period,
        string $adAccount,
        string $credentialReference,
        string $consentReference,
        string $keyId,
        string $nonce,
        int $issuedAt,
        int $expiresAt,
        #[\SensitiveParameter] string $secret
    ):array {
        if(getenv('APP_ENV')!=='testing' ||
           getenv('PORTAL_PERF_ADS_S2S_READ_GRANT_LAB')!=='1'){
            throw new RuntimeException('Portal Ads read grant signer is disabled.');
        }
        if(!preg_match('/\Atenant_[A-Za-z0-9_-]{8,64}\z/D',$tenant) ||
           !preg_match('/\Aagreement_[A-Za-z0-9_-]{8,64}\z/D',$agreement) ||
           !preg_match('/\A[0-9]{4}-(0[1-9]|1[0-2])\z/D',$period) ||
           !preg_match('/\Aadacct_[A-Za-z0-9]{3,64}\z/D',$adAccount) ||
           !preg_match('/\Aref_[A-Za-z0-9_-]{12,96}\z/D',$credentialReference) ||
           !preg_match('/\Aconsent_[A-Za-z0-9_-]{8,64}\z/D',$consentReference) ||
           !preg_match('/\Ak_[A-Za-z0-9_-]{4,64}\z/D',$keyId) ||
           !preg_match('/\A[A-Za-z0-9_-]{16,96}\z/D',$nonce) ||
           $issuedAt<=0 || $expiresAt<=$issuedAt ||
           $expiresAt-$issuedAt>120 || strlen($secret)<32){
            throw new InvalidArgumentException('Invalid synthetic Ads-read delegation.');
        }
        $envelope=[
            'version'=>'1',
            'type'=>'imagine-portal.ads-read-delegation',
            'issuer'=>'imagine-portal-ci',
            'audience'=>'imagine-performance-ai.ads-read-lab',
            'tenant_id'=>$tenant,
            'agreement_id'=>$agreement,
            'period'=>$period,
            'provider'=>'chatgpt_ads',
            'ad_account_id'=>$adAccount,
            'credential_reference'=>$credentialReference,
            'consent_reference'=>$consentReference,
            'permission'=>'ads.insights.read',
            'can_mutate_ads'=>false,
            'can_charge'=>false,
            'issued_at'=>$issuedAt,
            'expires_at'=>$expiresAt,
            'key_id'=>$keyId,
            'nonce'=>$nonce,
        ];
        $bytes="imagine:portal:performance:ads-read-grant:lab:v1\n".
            json_encode(self::sorted($envelope),
                JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        $envelope['signature']=hash_hmac('sha256',$bytes,$secret);
        return $envelope;
    }

    /** @param array<string,mixed> $map @return array<string,mixed> */
    private static function sorted(array $map):array
    {
        ksort($map,SORT_STRING);
        foreach($map as $k=>$v) {
            if(!is_string($k) ||
               (!is_string($v)&&!is_int($v)&&!is_bool($v))) {
                throw new InvalidArgumentException('Noncanonical delegation field.');
            }
        }
        return $map;
    }
}
