<?php

declare(strict_types=1);

namespace App\Domain\SitesIntegration;

/**
 * Must consult the official settlement/PSP ledger at issuance time.
 * No browser flag, synthetic approved string, cached rehearsal row or
 * user-controlled transaction receipt is acceptable.
 */
interface PortalCurrentSettlementSource
{
    public function findCurrentSettledOrder(string $orderRef): ?PortalSettlementEvidence;
}
