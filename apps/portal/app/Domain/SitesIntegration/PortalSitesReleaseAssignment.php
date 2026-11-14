<?php

declare(strict_types=1);

namespace App\Domain\SitesIntegration;

use InvalidArgumentException;

/**
 * Immutable trusted association across ONE commercial order and ONE precise
 * reviewed Sites release. Never construct from browser, webhook or signed
 * assertion: the operational source must verify server-side membership.
 */
final readonly class PortalSitesReleaseAssignment
{
    public function __construct(
        public string $tenantRef,
        public string $orderRef,
        public string $contractRef,
        public int $contractRevision,
        public string $productCode,
        public string $siteRef,
        public string $releaseRef,
        public string $artifactDigest,
        public int $editorialRevision,
        public int $bindingRevision,
    ) {
        foreach ([$tenantRef,$orderRef,$contractRef,$siteRef,$releaseRef] as $ref) {
            if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D',$ref)!==1) {
                throw new InvalidArgumentException('Untrusted release association.');
            }
        }
        if ($contractRevision<1 || $editorialRevision<1 || $bindingRevision<1 ||
            !in_array($productCode,['onepage','landing_page'],true) ||
            preg_match('/\A[a-f0-9]{64}\z/D',$artifactDigest)!==1) {
            throw new InvalidArgumentException('Invalid release association.');
        }
    }
}
