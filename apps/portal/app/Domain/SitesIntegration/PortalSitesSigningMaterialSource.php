<?php

declare(strict_types=1);

namespace App\Domain\SitesIntegration;

/**
 * Candidate trusted source: must return a self-consistent secret+epoch
 * from outside any caller-controlled request. No real IAM provider yet.
 */
interface PortalSitesSigningMaterialSource
{
    public function currentFor(string $issuer, string $audience): ?CandidatePortalSitesSigningMaterial;
}
