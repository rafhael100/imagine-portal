<?php

declare(strict_types=1);

namespace App\Domain\SitesIntegration;

/** Default closed. No official approved current-head database reader yet. */
final class DenyingCurrentApprovedOnepageHeadSource implements CurrentApprovedOnepageHeadSource
{
    public function readCurrent(
        string $tenantRef, string $orderRef, string $siteRef,
    ): ?ApprovedOnepageBriefSnapshot {
        return null;
    }
}
