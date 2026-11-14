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
        #[\SensitiveParameter] string $key
    ): array {
        if (getenv('APP_ENV') !== 'testing' || getenv('PORTAL_PERF_SIGN_LAB') !== '1') {
            throw new RuntimeException('Portal Performance signing lab disabled.');
        }
        if (!preg_match('/\Atenant_[A-Za-z0-9_-]{8,64}\z/D', $tenant) ||
            !preg_match('/\Ak_[A-Za-z0-9_-]{4,64}\z/D', $keyId) ||
            !preg_match('/\A[A-Za-z0-9_-]{16,96}\z/D', $nonce) ||
            $issued < 1 || $expires <= $issued || $expires - $issued > 300 ||
            strlen($key) < 32) {
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
        $bytes = "imagine:portal:performance:quote:v1\n" .
            json_encode(self::canonicalMap($envelope), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (strlen($bytes) > 8250) {
            throw new InvalidArgumentException('Synthetic signed envelope exceeds allowed size.');
        }
        $envelope['signature'] = hash_hmac('sha256', $bytes, $key);
        return $envelope;
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
