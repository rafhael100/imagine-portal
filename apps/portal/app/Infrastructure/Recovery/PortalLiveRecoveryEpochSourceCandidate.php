<?php
declare(strict_types=1);

namespace App\Infrastructure\Recovery;

/**
 * Trusted server-side current Portal epoch source, never request-supplied.
 * A future live implementation must enforce tenant isolation and be
 * independent from browser-supplied identity or cached restore snapshots.
 */
interface PortalLiveRecoveryEpochSourceCandidate
{
    public function currentEpoch(): array;
}
