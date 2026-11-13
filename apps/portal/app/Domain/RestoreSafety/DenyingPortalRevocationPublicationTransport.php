<?php

declare(strict_types=1);

namespace App\Domain\RestoreSafety;

use App\Domain\Organizations\OrganizationId;
use SensitiveParameter;

/** No network, no signing authority, and no operational revocation claims. */
final class DenyingPortalRevocationPublicationTransport implements PortalRevocationPublicationTransport
{
    public function publishForReview(
        OrganizationId $tenant,
        string $eventId,
        int $primaryBackupSequence,
        int $minimumSessionFloor,
        int $minimumMembershipFloor,
        #[SensitiveParameter] string $challenge,
    ): ?string {
        return null;
    }
}
