<?php

declare(strict_types=1);

namespace App\Domain\SitesIntegration;

/** No trusted Portal source exists in this prototype: always deny. */
final class DenyingReviewHandoffAuthoritySource implements ReviewHandoffAuthoritySource
{
    public function currentFor(
        string $tenantRef,
        string $siteRef,
        string $workflowRef,
    ): ?ReviewHandoffAuthoritySnapshot {
        return null;
    }
}
