<?php

declare(strict_types=1);

namespace App\Domain\SitesIntegration;

use RuntimeException;
use stdClass;
use Throwable;

/**
 * Strict BODY parser for a FUTURE private Portal S2S current-head route.
 *
 * The only accepted wire format is the canonical, flat 5-key request emitted
 * by the Sites Onepage transport. It is intentionally byte-canonical:
 * duplicates, alternate encodings, whitespace, reordered or unknown fields
 * fail closed rather than being normalized into a different authorization
 * decision. This class NEVER authenticates mTLS, authorizes a tenant, reads a
 * briefing, or makes an HTTP response. A controller MUST derive service
 * identity separately from a verified mTLS peer and scope it to the tenant.
 */
final class OnepageCurrentHeadS2sRequestCandidate
{
    private const SCHEMA = 'sites.onepage-current-head.fetch.v1';

    /**
     * @return array{tenantRef:string,orderRef:string,siteRef:string}
     */
    public static function decode(string $body): array
    {
        try {
            if ($body === '' || strlen($body) > 1024) {
                throw new RuntimeException();
            }

            $decoded = json_decode($body, false, 4, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            if (!$decoded instanceof stdClass) {
                throw new RuntimeException();
            }
            $fields = ['schemaVersion', 'tenantRef', 'orderRef', 'siteRef', 'productCode'];
            if (array_keys(get_object_vars($decoded)) !== $fields ||
                $decoded->schemaVersion !== self::SCHEMA ||
                $decoded->productCode !== 'onepage') {
                throw new RuntimeException();
            }
            foreach ([$decoded->tenantRef, $decoded->orderRef] as $ref) {
                if (!is_string($ref) ||
                    preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $ref) !== 1) {
                    throw new RuntimeException();
                }
            }
            if (!is_string($decoded->siteRef) ||
                preg_match('/\A[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\z/D',
                    $decoded->siteRef) !== 1) {
                throw new RuntimeException();
            }

            $canonical = json_encode([
                'schemaVersion' => self::SCHEMA,
                'tenantRef' => $decoded->tenantRef,
                'orderRef' => $decoded->orderRef,
                'siteRef' => $decoded->siteRef,
                'productCode' => 'onepage',
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

            if (!is_string($canonical) || !hash_equals($canonical, $body)) {
                throw new RuntimeException();
            }

            // No authenticatedServiceRef in the HTTP body or return type.
            return [
                'tenantRef' => $decoded->tenantRef,
                'orderRef' => $decoded->orderRef,
                'siteRef' => $decoded->siteRef,
            ];
        } catch (Throwable) {
            // Do not reveal whether a tenant/order/site exists or was denied.
            throw new RuntimeException('PORTAL_ONEPAGE_CURRENT_HEAD_REQUEST_DENIED');
        }
    }
}
