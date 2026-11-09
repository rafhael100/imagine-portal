<?php

declare(strict_types=1);

namespace App\Domain\SitesIntegration;

final class DenyingPortalReviewReplayFence implements PortalReviewReplayFence
{
    public function rememberOnce(string $issuer,string $nonce,int $expiresAt):bool
    {
        return false;
    }
}
