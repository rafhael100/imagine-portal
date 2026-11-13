<?php

declare(strict_types=1);

namespace App\Domain\RestoreSafety;

use App\Domain\Organizations\OrganizationId;
use SensitiveParameter;

/** Production-safe default: do not connect or claim an external witness. */
final class DenyingPortalWitnessChallengeTransport implements PortalWitnessChallengeTransport
{
    public function fetchAttestation(
        OrganizationId $organization,
        #[SensitiveParameter] string $challenge,
    ): ?string {
        return null;
    }
}
