<?php
declare(strict_types=1);

namespace App\Homologation\Commerce;

use InvalidArgumentException;
use RuntimeException;

/**
 * CI-ONLY V6 protocol candidate: contract/agreement ID is part of the
 * domain-separated HMAC envelope. Isolated from active V4 and V5 test signer.
 * NO Portal IAM/consent, Asaas, production keys or Ads accounts.
 */
final class PortalPerformanceV6AgreementLabIssuer
{
    /** @param array<string,int> $media */
    public static function issue(
        PerformanceTriChannelV5CandidateCatalog $catalog,
        array $media,
        string $tenant,
        string $agreementId,
        string $period,
        string $keyId,
        string $nonce,
        int $issued,
        int $expires,
        #[\SensitiveParameter] string $secret
    ): array {
        if (getenv('APP_ENV') !== 'testing' ||
            getenv('PORTAL_PERF_V6_AGREEMENT_LAB') !== '1') {
            throw new RuntimeException('V6 agreement signing disabled outside CI.');
        }
        if (!preg_match('/\Atenant_[A-Za-z0-9_-]{8,64}\z/D',$tenant) ||
            !preg_match('/\Aagreement_[A-Za-z0-9_-]{8,64}\z/D',$agreementId) ||
            !preg_match('/\A([0-9]{4})-(0[1-9]|1[0-2])\z/D',$period) ||
            !preg_match('/\Ak_[A-Za-z0-9_-]{4,64}\z/D',$keyId) ||
            !preg_match('/\A[A-Za-z0-9_-]{16,96}\z/D',$nonce) ||
            $issued <= 0 || $expires <= $issued ||
            $expires - $issued > 300 || strlen($secret)<32) {
            throw new InvalidArgumentException('Invalid V6 signed agreement identity or expiry.');
        }
        // The catalog is server-side and has owner-approved, but NOT active,
        // channel minima. Never accept arbitrary fees or amounts-now.
        $quote=$catalog->quote($media);
        if (($quote['real_charge_enabled'] ?? null) !== false ||
            ($quote['media_paid_directly_to_platforms'] ?? null) !== true ||
            ($quote['management_fee_basis_points'] ?? null) !== 2000 ||
            ($quote['catalog_version'] ?? null) !== $catalog->version()) {
            throw new RuntimeException('Signed catalog is not an isolated candidate.');
        }
        $envelope=[
            'agreement_id'=>$agreementId,
            'audience'=>'imagine-performance-finance-v6-agreement-preview',
            'billing_period'=>$period,
            'contract_version'=>'performance-trichannel-v6-agreement-candidate',
            'expires_at'=>$expires,
            'issued_at'=>$issued,
            'key_id'=>$keyId,
            'nonce'=>$nonce,
            'quote'=>$quote,
            'tenant_id'=>$tenant,
        ];
        $bytes="imagine:portal:performance:quote:v6\n".
            json_encode(self::canonical($envelope),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if(strlen($bytes)>8250) {
            throw new InvalidArgumentException('V6 candidate envelope too large.');
        }
        $envelope['signature']=hash_hmac('sha256',$bytes,$secret);
        return $envelope;
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private static function canonical(array $input): array
    {
        if ($input===[] || array_is_list($input)) {
            throw new InvalidArgumentException('V6 canonical envelope requires named fields.');
        }
        ksort($input,SORT_STRING);
        foreach($input as $k=>$v) {
            if(!is_string($k)) throw new InvalidArgumentException('Invalid key.');
            if(is_array($v)) $input[$k]=self::canonical($v);
            elseif(!is_string($v)&&!is_int($v)&&!is_bool($v)){
                throw new InvalidArgumentException('Noncanonical V6 primitive.');
            }
        }
        return $input;
    }
}
