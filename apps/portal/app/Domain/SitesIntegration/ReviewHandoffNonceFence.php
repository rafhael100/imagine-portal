<?php

declare(strict_types=1);

namespace App\Domain\SitesIntegration;

/** Production adapter must atomically persist nonces, shared by workers. */
interface ReviewHandoffNonceFence
{
    public function consumeOnce(string $issuer,string $nonce,int $expiresAt):bool;
}
