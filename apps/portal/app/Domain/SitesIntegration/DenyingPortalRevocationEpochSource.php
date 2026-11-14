<?php

declare(strict_types=1);

namespace App\Domain\SitesIntegration;

final class DenyingPortalRevocationEpochSource implements PortalCurrentRevocationEpochSource
{
    public function readCurrentActiveEpoch(
        string $tenantRef,
        string $orderRef,
        string $contractRef,
        int $contractRevision
    ): ?int {
        return null;
    }
}
