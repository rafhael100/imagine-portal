<?php

declare(strict_types=1);

namespace App\Domain\SitesIntegration;

/**
 * Candidate ONLY: implementation must independently read CURRENT official
 * Portal entitlements/revocation. Never trust a boolean/tenant sent by Sites.
 */
interface ReviewHandoffAuthoritySource
{
    public function currentFor(
        string $tenantRef,
        string $siteRef,
        string $workflowRef,
    ): ?ReviewHandoffAuthoritySnapshot;
}
