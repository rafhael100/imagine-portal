<?php

declare(strict_types=1);

namespace App\Domain\SitesIntegration;

use RuntimeException;
use Throwable;

/**
 * Unbound, read-only integration seam for the future private Sites -> Portal
 * Onepage current-head request. It is NOT a controller, service binding or
 * credential validator. The host must supply a service ref derived from a
 * verified mTLS peer, never from request bytes, headers or user claims.
 *
 * Parsing happens before any source read. Approval, tenant scoping and
 * revocation remain the responsibility of the independently injected
 * service witness and official Portal readers. No publishing/acceptance grant.
 */
final readonly class OnepageCurrentHeadS2sDispatchCandidate
{
    public function __construct(
        private OnepageCurrentHeadS2sResponseCandidate $response =
            new OnepageCurrentHeadS2sResponseCandidate(),
    ) {}

    public function dispatch(string $requestBytes, string $verifiedPeerServiceRef): string
    {
        try {
            if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $verifiedPeerServiceRef) !== 1) {
                throw new RuntimeException();
            }
            $selection = OnepageCurrentHeadS2sRequestCandidate::decode($requestBytes);
            return $this->response->prepare(
                $verifiedPeerServiceRef,
                $selection['tenantRef'],
                $selection['orderRef'],
                $selection['siteRef'],
            );
        } catch (Throwable) {
            // Same error for malformed wire, foreign tenant, revoked approval,
            // invalid peer and unavailable record. Never echo private inputs.
            throw new RuntimeException('PORTAL_ONEPAGE_CURRENT_HEAD_DISPATCH_DENIED');
        }
    }
}
