<?php

declare(strict_types=1);

namespace App\Domain\SitesIntegration;

final readonly class DenyingApprovedOnepageBriefSnapshotSource implements ApprovedOnepageBriefSnapshotSource
{
    public function readExact(string $orderRef, string $siteRef, int $sourceVersion): ?ApprovedOnepageBriefSnapshot
    {
        return null;
    }
}
