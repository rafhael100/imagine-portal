<?php

declare(strict_types=1);

namespace App\Domain\SitesIntegration;

/** Fail closed when no durable replay store is wired. */
final class DenyingReviewHandoffNonceFence implements ReviewHandoffNonceFence
{
    public function consumeOnce(string $issuer,string $nonce,int $expiresAt):bool
    {
        return false;
    }
}
