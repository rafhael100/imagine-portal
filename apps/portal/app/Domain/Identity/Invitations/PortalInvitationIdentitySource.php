<?php

declare(strict_types=1);

namespace App\Domain\Identity\Invitations;

use App\Domain\Organizations\OrganizationId;

/**
 * F110: non-operational boundary for a FUTURE first-party Portal-only
 * authenticator. Never use a caller-constructed DTO, CRM session, request
 * header, email, bearer token or UUID as identity provenance.
 */
interface PortalInvitationIdentitySource
{
    public function findFor(
        string $opaquePortalSessionReference,
        OrganizationId $selectedOrganization,
    ): ?PortalInvitationActorEvidence;
}
