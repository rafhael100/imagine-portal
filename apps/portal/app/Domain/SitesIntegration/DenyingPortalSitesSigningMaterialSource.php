<?php

declare(strict_types=1);

namespace App\Domain\SitesIntegration;

/** Fail-closed until a vetted Portal S2S signing authority exists. */
final class DenyingPortalSitesSigningMaterialSource implements PortalSitesSigningMaterialSource
{
    public function currentFor(string $issuer, string $audience): ?CandidatePortalSitesSigningMaterial
    {
        return null;
    }
}
