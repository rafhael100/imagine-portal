<?php

declare(strict_types=1);

namespace App\Domain\SitesIntegration;

/**
 * Independently read current revocation/entitlement state from a trusted
 * record, not from the customer's body or an issued proof.
 *
 * Null means revoked, unknown or temporarily unavailable: DENY.
 */
interface PortalCurrentRevocationEpochSource
{
    public function readCurrentActiveEpoch(
        string $tenantRef,
        string $orderRef,
        string $contractRef,
        int $contractRevision
    ): ?int;
}
