<?php

declare(strict_types=1);

namespace App\Domain\Identity\Browser;

final class DenyingPortalBrowserAuthentication implements PortalBrowserAuthentication
{
    public function beginPassword(string $email, string $password, string $organization, string $sourceIp): ?string
    {
        return null;
    }

    public function completeMfa(string $pendingReference, string $code, string $sourceIp): ?string
    {
        return null;
    }

    public function isActiveBusinessSession(string $reference): bool
    {
        return false;
    }

    public function revoke(string $reference): bool
    {
        return false; // Cannot attest that any durable session was revoked.
    }
}
