<?php

declare(strict_types=1);

namespace App\Domain\RestoreSafety;

use App\Domain\Organizations\OrganizationId;
use SensitiveParameter;

/**
 * Future externally operated witness request/reply seam.
 *
 * An implementation would need independent AppSec/DBA approval and a durable
 * out-of-rollback monotonic service. A mock signed response is NOT proof of
 * that independence. No live transport is registered.
 */
interface PortalWitnessChallengeTransport
{
    /** Raw signed attestation, or null on any communication failure. */
    public function fetchAttestation(
        OrganizationId $organization,
        #[SensitiveParameter] string $challenge,
    ): ?string;
}
