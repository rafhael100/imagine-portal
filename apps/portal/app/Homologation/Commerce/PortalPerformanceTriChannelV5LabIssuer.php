<?php
declare(strict_types=1);

namespace App\Homologation\Commerce;

use InvalidArgumentException;
use RuntimeException;

/**
 * HMAC v5 three-channel CANDIDATE, intentionally separate from v1/v2.
 * CI-only: no Portal IAM, key vault, S2S authentication, Asaas or Ads writes.
 */
final class PortalPerformanceTriChannelV5LabIssuer
{
    /** @param array<string,int> $plannedMedia */
    public static function issue(
        PerformanceTriChannelV5CandidateCatalog $catalog,
        array $plannedMedia,
        string $tenant,
        string $billingPeriod,
        string $keyId,
        string $nonce,
        int $issued,
        int $expires,
        #[\SensitiveParameter] string $secret
    ): array {
        if (getenv('APP_ENV') !== 'testing' ||
            getenv('PORTAL_PERF_TRICHANNEL_V5_LAB') !== '1') {
            throw new RuntimeException('Trichannel v5 signer cannot run outside laboratory.');
        }
        if (!preg_match('/\Atenant_[A-Za-z0-9_-]{8,64}\z/D', $tenant) ||
            !preg_match('/\A[0-9]{4}-(0[1-9]|1[0-2])\z/D', $billingPeriod) ||
            !preg_match('/\Ak_[A-Za-z0-9_-]{4,64}\z/D', $keyId) ||
            !preg_match('/\A[A-Za-z0-9_-]{16,96}\z/D', $nonce) ||
            $issued <= 0 || $expires <= $issued ||
            $expires - $issued > 300 || strlen($secret) < 32) {
            throw new InvalidArgumentException('Invalid v5 signing scope.');
        }
        $quote = $catalog->quote($plannedMedia);
        if (($quote['real_charge_enabled'] ?? null) !== false ||
            ($quote['media_paid_directly_to_platforms'] ?? null) !== true ||
            ($quote['management_fee_basis_points'] ?? null) !== 2000 ||
            ($quote['catalog_version'] ?? null) !== $catalog->version()) {
            throw new RuntimeException('Candidate source cannot issue an operational checkout.');
        }
        $envelope = [
            'audience' => 'imagine-performance-finance-v5-candidate-preview',
            'billing_period' => $billingPeriod,
            'contract_version' => 'performance-trichannel-v5-candidate',
            'expires_at' => $expires,
            'issued_at' => $issued,
            'key_id' => $keyId,
            'nonce' => $nonce,
            'quote' => $quote,
            'tenant_id' => $tenant,
        ];
        $canonical = self::canonical($envelope);
        $bytes = "imagine:portal:performance:quote:v5\n" . json_encode(
            $canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        if (strlen($bytes) > 8250) {
            throw new InvalidArgumentException('Candidate envelope too large.');
        }
        return $envelope + ['signature' => hash_hmac('sha256', $bytes, $secret)];
    }

    /** @param array<string,mixed> $map @return array<string,mixed> */
    private static function canonical(array $map): array
    {
        if ($map === [] || array_is_list($map)) {
            throw new InvalidArgumentException('Expected named signed values.');
        }
        ksort($map, SORT_STRING);
        foreach ($map as $k => $v) {
            if (!is_string($k)) throw new InvalidArgumentException('Invalid key.');
            if (is_array($v)) $map[$k] = self::canonical($v);
            elseif (!is_string($v) && !is_int($v) && !is_bool($v)) {
                throw new InvalidArgumentException('Invalid canonical type.');
            }
        }
        return $map;
    }
}
