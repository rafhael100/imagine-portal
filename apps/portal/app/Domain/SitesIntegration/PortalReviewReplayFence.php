<?php

declare(strict_types=1);

namespace App\Domain\SitesIntegration;

/** Implement durably and atomically in trusted storage before any real service. */
interface PortalReviewReplayFence
{
    public function rememberOnce(string $issuer,string $nonce,int $expiresAt):bool;
}
