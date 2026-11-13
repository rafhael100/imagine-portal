<?php

declare(strict_types=1);

namespace App\Domain\RestoreSafety;

use App\Domain\Organizations\OrganizationId;
use SensitiveParameter;

/**
 * Hypothetical external witness publication API, with no implementation
 * permitted in the current release. Returning a signed receipt alone does
 * not prove independent stable storage or linearizable revocation.
 */
interface PortalRevocationPublicationTransport
{
    public function publishForReview(
        OrganizationId $tenant,
        string $eventId,
        int $primaryBackupSequence,
        int $minimumSessionFloor,
        int $minimumMembershipFloor,
        #[SensitiveParameter] string $challenge,
    ): ?string;
}
