<?php

declare(strict_types=1);

namespace App\Domain\SitesIntegration;

use InvalidArgumentException;

/**
 * Immutable projection of an already-authorized site order.
 * This class is NOT proof of authentication, membership, payment or eligibility:
 * the production PortalSiteContractSource must verify those in official backends.
 */
final readonly class SiteContractSnapshot
{
    public function __construct(
        public string $tenantRef,
        public string $orderRef,
        public string $contractRef,
        public int $contractRevision,
        public string $productCode,
    ) {
        foreach ([$tenantRef, $orderRef, $contractRef] as $ref) {
            if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $ref)) {
                throw new InvalidArgumentException('Invalid reference.');
            }
        }
        if ($contractRevision < 1 || !in_array($productCode, ['onepage', 'landing_page'], true)) {
            throw new InvalidArgumentException('Invalid Sites contract snapshot.');
        }
    }
}
