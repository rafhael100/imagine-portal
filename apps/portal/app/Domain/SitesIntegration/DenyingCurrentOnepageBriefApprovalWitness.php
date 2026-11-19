<?php

declare(strict_types=1);

namespace App\Domain\SitesIntegration;

final readonly class DenyingCurrentOnepageBriefApprovalWitness implements CurrentOnepageBriefApprovalWitness
{
    public function isCurrent(ApprovedOnepageBriefSnapshot $brief): bool
    {
        return false;
    }
}
