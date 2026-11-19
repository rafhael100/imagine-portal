<?php

declare(strict_types=1);

namespace App\Domain\SitesIntegration;

/**
 * Future authorized Portal reader. Must own session/tenant/order/content
 * approval checks and return immutable original bytes. No adapter is active.
 */
interface ApprovedOnepageBriefSnapshotSource
{
    public function readExact(string $orderRef, string $siteRef, int $sourceVersion): ?ApprovedOnepageBriefSnapshot;
}
