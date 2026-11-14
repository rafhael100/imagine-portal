<?php

declare(strict_types=1);

namespace App\Domain\SitesIntegration;

/** Default provider deliberately exposes no customer acceptance. */
final class DenyingPortalCurrentClientAcceptanceSource implements PortalCurrentClientAcceptanceSource
{
    public function readCurrent(
        string $siteRef,
        string $releaseRef,
        int $editorialRevision,
    ): ?PortalClientAcceptanceSnapshot {
        return null;
    }
}
