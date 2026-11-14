<?php

declare(strict_types=1);

namespace App\Domain\SitesIntegration;

/** Fail-closed until an approved, authenticated mTLS/IAM adapter exists. */
final class DenyingPortalVerifiedSitesService implements PortalVerifiedSitesServiceIdentity
{
    public function isVerifiedSitesService(): bool
    {
        return false;
    }
}
