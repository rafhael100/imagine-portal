<?php

declare(strict_types=1);

namespace App\Domain\SitesIntegration;

/**
 * Authorization of a verified S2S identity, NOT a client-supplied string.
 * A future endpoint must obtain service identity from authenticated mTLS
 * termination (and enforce service ACL, tenant context and request replay).
 */
interface PortalOnepageHeadServiceWitness
{
    public function permits(
        string $authenticatedServiceRef,
        string $tenantRef,
        string $orderRef,
        string $siteRef,
    ): bool;
}
