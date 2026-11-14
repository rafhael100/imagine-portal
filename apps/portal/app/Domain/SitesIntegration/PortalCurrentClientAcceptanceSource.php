<?php

declare(strict_types=1);

namespace App\Domain\SitesIntegration;

/**
 * Trusted Portal-side source of recorded client acceptance.
 * The implementation must derive tenant, revision, authenticated actor,
 * immutable digest and current acceptance from independently controlled
 * IAM/consent data; never from an incoming HTTP body or local browser state.
 */
interface PortalCurrentClientAcceptanceSource
{
    public function readCurrent(
        string $siteRef,
        string $releaseRef,
        int $editorialRevision,
    ): ?PortalClientAcceptanceSnapshot;
}
