<?php
declare(strict_types=1);

namespace App\Homologation\Commerce;

use InvalidArgumentException;
use RuntimeException;

/**
 * OFFLINE laboratory issuer. Not an endpoint or an authorization grant.
 * A production issuer requires trusted Portal IAM/tenant binding, key lifecycle,
 * a durable outbox and an authenticated service transport.
 */
final class PortalPerformanceQuoteLabIssuer
{
    /**
     * @param array<string,int> $mediaByChannel planned media in BRL cents
     * @return array<string,mixed> Signed synthetic envelope for test verifier
     */
    public static function issue(
        ApprovedOfferCatalog $catalog,
        array $mediaByChannel,
        string $tenant,
        string $keyId,
        string $nonce,
        int $issued,
        int $expires,
        #[\SensitiveParameter] string $key,
        ?string $billingPeriod = null
    ): array {
        if (getenv('APP_ENV') !== 'testing' || getenv('PORTAL_PERF_SIGN_LAB') !== '1') {
            throw new RuntimeException('Portal Performance signing lab disabled.');
        }
        if (!preg_match('/\Atenant_[A-Za-z0-9_-]{8,64}\z/D', $tenant) ||
            !preg_match('/\Ak_[A-Za-z0-9_-]{4,64}\z/D', $keyId) ||
            !preg_match('/\A[A-Za-z0-9_-]{16,96}\z/D', $nonce) ||
            $issued < 1 || $expires <= $issued || $expires - $issued > 300 ||
            strlen($key) < 32 ||
            ($billingPeriod !== null && !preg_match('/\A[0-9]{4}-(0[1-9]|1[0-2])\z/D', $billingPeriod))) {
            throw new InvalidArgumentException('Invalid synthetic issuer scope.');
        }
        // Produce a server-owned quote, not a caller-provided amount/fee.
        $quote = $catalog->quote('performance', $mediaByChannel);
        if (($quote['real_charge_enabled'] ?? null) !== false ||
            ($quote['media_paid_directly_to_platforms'] ?? null) !== true ||
            ($quote['management_fee_basis_points'] ?? null) !== 2000 ||
            ($quote['catalog_version'] ?? null) !== $catalog->version()) {
            throw new RuntimeException('Portal commercial catalog not allowed for lab issuer.');
        }
        $envelope = [
            'audience' => 'imagine-performance-finance-preview',
            'expires_at' => $expires,
            'issued_at' => $issued,
            'key_id' => $keyId,
            'nonce' => $nonce,
            'quote' => $quote,
            'tenant_id' => $tenant,
        ];
        if ($billingPeriod !== null) {
            $envelope['billing_period'] = $billingPeriod;
        }
        // Domain-separated signature: legacy v1 has no month, v2 binds the exact YYYY-MM.
        $prefix = $billingPeriod === null ? 'v1' : 'v2';
        $bytes = "imagine:portal:performance:quote:{$prefix}\n" .
            json_encode(self::canonicalMap($envelope), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (strlen($bytes) > 8250) {
            throw new InvalidArgumentException('Synthetic signed envelope exceeds allowed size.');
        }
        $envelope['signature'] = hash_hmac('sha256', $bytes, $key);
        return $envelope;
    }

    /**
     * Strict CI-only receiver: no v1 envelopes and no cross-month reuse.
     * Does not grant payment, advertising spend, contract or account access.
     *
     * @param array<string,mixed> $envelope
     * @return array<string,mixed> The rederived server catalog quote only
     */
    public static function verifyBillingPeriod(
        ApprovedOfferCatalog $catalog,
        array $envelope,
        string $expectedTenant,
        string $expectedKeyId,
        string $expectedPeriod,
        int $now,
        #[\SensitiveParameter] string $key
    ): array {
        if (getenv('APP_ENV')!=='testing' || getenv('PORTAL_PERF_SIGN_LAB')!=='1') {
            throw new RuntimeException('Portal Performance verification lab disabled.');
        }
        $required=[
            'audience','billing_period','expires_at','issued_at','key_id',
            'nonce','quote','signature','tenant_id'
        ];
        $actual=array_keys($envelope);
        sort($actual,SORT_STRING);
        sort($required,SORT_STRING);
        if ($actual!==$required ||
            ($envelope['audience']??null)!=='imagine-performance-finance-preview' ||
            ($envelope['tenant_id']??null)!==$expectedTenant ||
            ($envelope['key_id']??null)!==$expectedKeyId ||
            ($envelope['billing_period']??null)!==$expectedPeriod ||
            !preg_match('/\A[0-9]{4}-(0[1-9]|1[0-2])\z/D',$expectedPeriod) ||
            !is_int($envelope['issued_at']??null) ||
            !is_int($envelope['expires_at']??null) ||
            $envelope['issued_at']<1 ||
            $envelope['expires_at']<=$envelope['issued_at'] ||
            $envelope['expires_at']-$envelope['issued_at']>300 ||
            $now<$envelope['issued_at'] || $now>=$envelope['expires_at'] ||
            !is_string($envelope['nonce']??null) ||
            !preg_match('/\A[A-Za-z0-9_-]{16,96}\z/D',$envelope['nonce']) ||
            !is_string($envelope['signature']??null) ||
            !preg_match('/\A[a-f0-9]{64}\z/D',$envelope['signature']) ||
            !is_array($envelope['quote']??null) ||
            strlen($key)<32) {
            throw new InvalidArgumentException('Untrusted quote envelope.');
        }
        $signed=$envelope;
        unset($signed['signature']);
        $bytes="imagine:portal:performance:quote:v2\n".
            json_encode(self::canonicalMap($signed),JSON_THROW_ON_ERROR |
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (strlen($bytes)>8250 ||
            !hash_equals(hash_hmac('sha256',$bytes,$key),$envelope['signature'])) {
            throw new InvalidArgumentException('Quote signature mismatch.');
        }
        $quote=$envelope['quote'];
        if (($quote['product']??null)!=='performance' ||
            !is_array($quote['media_by_channel_cents']??null)) {
            throw new InvalidArgumentException('Unapproved quote scope.');
        }
        // A signed payload may still be stale after pricing/version changes:
        // rederive its commercial terms from the locally approved catalog.
        $canonical=$catalog->quote('performance',$quote['media_by_channel_cents']);
        if ($quote!==$canonical ||
            ($quote['real_charge_enabled']??null)!==false) {
            throw new InvalidArgumentException('Stale or altered quote terms.');
        }
        return $canonical;
    }

    /** @param array<string,mixed> $map */
    private static function canonicalMap(array $map): array
    {
        if ($map === [] || array_is_list($map)) {
            throw new InvalidArgumentException('Signed map requires named fields.');
        }
        ksort($map, SORT_STRING);
        foreach ($map as $field => $value) {
            if (!is_string($field)) throw new InvalidArgumentException('Invalid map field.');
            if (is_array($value)) {
                $map[$field] = self::canonicalMap($value);
            } elseif (!is_int($value) && !is_string($value) && !is_bool($value)) {
                throw new InvalidArgumentException('Invalid canonical primitive.');
            }
        }
        return $map;
    }
}
