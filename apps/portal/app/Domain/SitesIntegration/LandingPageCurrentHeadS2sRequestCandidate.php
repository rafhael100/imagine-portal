<?php

declare(strict_types=1);

namespace App\Domain\SitesIntegration;

use RuntimeException;
use stdClass;
use Throwable;

/**
 * Independent Landing Page BODY contract. It is not a Onepage alias.
 *
 * Canonicality protects the selector parse boundary from duplicate keys,
 * alternate number/escape encodings, extra privileges and product confusion.
 * Verified service identity, tenant/order authorization and a transactional
 * current approved Landing Page briefing are NOT provided by this parser.
 */
final class LandingPageCurrentHeadS2sRequestCandidate
{
    private const SCHEMA = 'sites.landing-page-current-head.fetch.v1';

    /** @return array{tenantRef:string,orderRef:string,siteRef:string} */
    public static function decode(string $body): array
    {
        try {
            if ($body === '' || strlen($body) > 1024 || str_contains($body, "\0")) {
                throw new RuntimeException();
            }
            $decoded = json_decode($body, false, 4, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            if (!$decoded instanceof stdClass ||
                array_keys(get_object_vars($decoded)) !==
                    ['schemaVersion','tenantRef','orderRef','siteRef','productCode'] ||
                $decoded->schemaVersion !== self::SCHEMA ||
                $decoded->productCode !== 'landing_page') {
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
                'schemaVersion'=>self::SCHEMA,
                'tenantRef'=>$decoded->tenantRef,
                'orderRef'=>$decoded->orderRef,
                'siteRef'=>$decoded->siteRef,
                'productCode'=>'landing_page',
            ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
            if (!is_string($canonical) || !hash_equals($canonical, $body)) {
                throw new RuntimeException();
            }
            return [
                'tenantRef'=>$decoded->tenantRef,
                'orderRef'=>$decoded->orderRef,
                'siteRef'=>$decoded->siteRef,
            ];
        } catch (Throwable) {
            throw new RuntimeException('PORTAL_LANDING_PAGE_HEAD_REQUEST_DENIED');
        }
    }
}
