<?php

declare(strict_types=1);

namespace App\Infrastructure\Recovery;

/**
 * F390: review-only digest revocation contract, no public HTTP bindings.
 * Only a server-side worker with a freshly verified, independently signed
 * revocation intent may call this. The digest is NOT an authentication token.
 *
 * TRUE means the restricted Portal database revoke() SECURITY DEFINER
 * function executed in autocommit. It cannot establish the target row
 * existed or authorize any request. A separate SQL status read is needed.
 */
interface PortalHashedSessionRevocationCandidate
{
    public function revokeSessionDigest(string $sessionSha256): bool;
}
