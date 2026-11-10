<?php

declare(strict_types=1);

namespace App\Domain\Identity\Invitations;

use App\Domain\Organizations\OrganizationId;
use Throwable;

/**
 * Candidate assessment for a future first-party Portal MFA acceptance flow.
 *
 * The default DI source ALWAYS denies. Even with synthetic positive evidence
 * the verdict can never consume an invitation, issue a session, activate
 * membership, or authorize HTTP. No SQL, mail, CRM or external IO.
 */
final readonly class PortalInvitationPreflight
{
    public function __construct(private PortalInvitationIdentitySource $identities)
    {
    }

    public function inspect(
        ?string $opaquePortalSessionReference,
        ?string $invitationBearer,
        ?OrganizationId $selectedOrganization,
        int $serverNow,
    ): PortalInvitationPreflightVerdict {
        if ($selectedOrganization === null
            || $opaquePortalSessionReference === null
            || $opaquePortalSessionReference === ''
            || strlen($opaquePortalSessionReference) > 256
            || $invitationBearer === null
            || $serverNow <= 0
            || PortalInvitationSecret::digestCandidate($invitationBearer) === null) {
            return PortalInvitationPreflightVerdict::blocked();
        }

        try {
            $evidence = $this->identities->findFor(
                $opaquePortalSessionReference,
                $selectedOrganization,
            );

            return $evidence !== null
                && $evidence->passesSyntheticPreflight($selectedOrganization, $serverNow)
                ? PortalInvitationPreflightVerdict::syntheticCandidate()
                : PortalInvitationPreflightVerdict::blocked();
        } catch (Throwable) {
            // No SQL error, provider exception, identity/tenant, token or
            // existence information escapes into an HTTP-capable verdict.
            return PortalInvitationPreflightVerdict::blocked();
        }
    }
}
