<?php

declare(strict_types=1);

namespace App\Domain\SitesIntegration;

/**
 * Separate CURRENT approval/revocation witness for the same immutable bytes,
 * content revision, tenant/order and approvalRef. Not implemented operationally.
 */
interface CurrentOnepageBriefApprovalWitness
{
    public function isCurrent(ApprovedOnepageBriefSnapshot $brief): bool;
}
