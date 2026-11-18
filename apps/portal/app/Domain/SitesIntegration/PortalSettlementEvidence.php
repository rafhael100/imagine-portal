<?php

declare(strict_types=1);

namespace App\Domain\SitesIntegration;

use InvalidArgumentException;

/**
 * Immutable projection from a trusted settlement source only.
 * Existence means the source verified finalized, non-refunded settlement.
 * This type alone does NOT prove the source's authority.
 */
final readonly class PortalSettlementEvidence
{
    public function __construct(
        public string $tenantRef,
        public string $orderRef,
        public string $contractRef,
        public int $contractRevision,
        public string $productCode,
        public string $settlementRef,
        public string $settlementSha256,
    ) {
        foreach ([
            $tenantRef, $orderRef, $contractRef, $settlementRef,
        ] as $value) {
            if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $value)) {
                throw new InvalidArgumentException('Invalid settlement reference.');
            }
        }
        if (strlen($settlementRef) < 8 ||
            $contractRevision < 1 ||
            !in_array($productCode, ['onepage','landing_page'],true) ||
            !preg_match('/\A[a-f0-9]{64}\z/D',$settlementSha256)) {
            throw new InvalidArgumentException('Invalid settlement evidence.');
        }
    }
}
