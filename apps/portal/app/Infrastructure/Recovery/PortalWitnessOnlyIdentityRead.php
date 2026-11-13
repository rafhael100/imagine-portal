<?php
declare(strict_types=1);

namespace App\Infrastructure\Recovery;

/**
 * F382: diagnostic observation only, not an authorization service.
 *
 * Results cannot grant HTTP access, session issuance or permission. This
 * contract must stay UNBOUND in the default Laravel service container.
 */
interface PortalWitnessOnlyIdentityRead
{
    /** Returns negative-only F381 diagnostic metadata. */
    public function inspectBusinessSession(string $reference): array;
}
