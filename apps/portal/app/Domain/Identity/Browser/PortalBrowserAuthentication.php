<?php

declare(strict_types=1);

namespace App\Domain\Identity\Browser;

/**
 * Trusted Portal-only adapter contract, never a caller-built identity snapshot.
 * Implementations must persist hashed references, throttle attempts, enforce
 * current identity/member/org revisions and MFA, and atomically rotate/revoke.
 * No implementation in this change can authenticate a customer.
 */
interface PortalBrowserAuthentication
{
    /** Validate password and active membership; issue a short-lived pending reference. */
    public function beginPassword(string $email, string $password, string $organization, string $sourceIp): ?string;

    /** Atomically consume pending evidence, verify enrolled MFA and rotate to business session. */
    public function completeMfa(string $pendingReference, string $code, string $sourceIp): ?string;

    /** Read durable current state; expired/revoked/non-business/stale revisions MUST return false. */
    public function isActiveBusinessSession(string $reference): bool;

    /** True only after durable, idempotent revocation has completed. */
    public function revoke(string $reference): bool;
}
