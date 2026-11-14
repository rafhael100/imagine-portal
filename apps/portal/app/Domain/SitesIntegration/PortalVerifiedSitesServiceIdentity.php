<?php

declare(strict_types=1);

namespace App\Domain\SitesIntegration;

/**
 * Contract to an independently verified S2S identity (mTLS peer cert +
 * authorized service principal). Implementations MUST NOT trust
 * X-Forwarded-Client-Cert or identity headers from an arbitrary HTTP client.
 */
interface PortalVerifiedSitesServiceIdentity
{
    public function isVerifiedSitesService(): bool;
}
