<?php

declare(strict_types=1);

namespace App\Domain\Identity\Invitations;

use App\Domain\Organizations\OrganizationId;

/**
 * The ONLY default source until a separately approved, first-party
 * authentication/MFA/revocation adapter exists. No request, SQL or CRM IO.
 */
final class DenyingPortalInvitationIdentitySource implements PortalInvitationIdentitySource
{
    public function findFor(
        string $opaquePortalSessionReference,
        OrganizationId $selectedOrganization,
    ): ?PortalInvitationActorEvidence {
        return null;
    }
}
