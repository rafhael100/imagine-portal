<?php

declare(strict_types=1);

namespace App\Infrastructure\Recovery;

/**
 * F391 review-only: distinguish actual revoked rows from missing rows.
 * Requires restricted PostgreSQL autocommit and HMAC/tenant-bound SQL status
 * function. Not a business authorization or session token checker.
 */
interface PortalHashedSessionStatusCandidate
{
    /**
     * Returns strictly: revoked|not_revoked|missing|inconsistent|denied.
     * This is a diagnostic read; "revoked" may refer to an old session
     * after restore. Remote online epoch fencing is still required.
     */
    public function inspectSessionDigestStatus(string $sessionSha256, string $organization): string;
}
