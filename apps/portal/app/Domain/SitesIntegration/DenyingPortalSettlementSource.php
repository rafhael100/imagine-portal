<?php

declare(strict_types=1);

namespace App\Domain\SitesIntegration;

final class DenyingPortalSettlementSource implements PortalCurrentSettlementSource
{
    public function findCurrentSettledOrder(string $orderRef): ?PortalSettlementEvidence
    {
        return null;
    }
}
