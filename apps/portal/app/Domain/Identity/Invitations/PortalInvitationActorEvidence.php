<?php

declare(strict_types=1);

namespace App\Domain\Identity\Invitations;

use App\Domain\Identity\PortalUserId;
use App\Domain\Organizations\OrganizationId;
use InvalidArgumentException;

/**
 * F110 SYNTHETIC/UNTRUSTED data structure for composition tests only.
 * It does not prove identity, possession of a factor or trusted provenance.
 * A future independently-reviewed source MUST establish provenance.
 */
final readonly class PortalInvitationActorEvidence
{
    public const MAX_SYNTHETIC_MFA_AGE_SECONDS = 300;

    public function __construct(
        public PortalUserId $actor,
        public OrganizationId $organization,
        public bool $sessionActive,
        public bool $emailVerified,
        public bool $mfaVerified,
        public int $sessionIssuedAt,
        public int $sessionExpiresAt,
        public int $emailVerifiedAt,
        public int $mfaVerifiedAt,
        public int $identityRevision,
        public int $sessionIdentityRevision,
        public int $membershipRevision,
        public int $sessionMembershipRevision,
        public int $organizationRevision,
        public int $sessionOrganizationRevision,
    ) {
        if ($sessionIssuedAt <= 0
            || $sessionExpiresAt <= $sessionIssuedAt
            || $emailVerifiedAt < 0
            || $mfaVerifiedAt < 0
            || $identityRevision <= 0
            || $sessionIdentityRevision <= 0
            || $membershipRevision <= 0
            || $sessionMembershipRevision <= 0
            || $organizationRevision <= 0
            || $sessionOrganizationRevision <= 0) {
            throw new InvalidArgumentException('Invalid synthetic invitation identity evidence.');
        }
    }

    public function passesSyntheticPreflight(
        OrganizationId $selectedOrganization,
        int $now,
    ): bool {
        return $now > 0
            && $this->sessionActive
            && $this->emailVerified
            && $this->mfaVerified
            && $this->organization->equals($selectedOrganization)
            && $now >= $this->sessionIssuedAt
            && $now < $this->sessionExpiresAt
            && $this->emailVerifiedAt > 0
            && $this->emailVerifiedAt <= $now
            && $this->mfaVerifiedAt >= $this->sessionIssuedAt
            && $this->mfaVerifiedAt <= $now
            && ($now - $this->mfaVerifiedAt) <= self::MAX_SYNTHETIC_MFA_AGE_SECONDS
            && $this->identityRevision === $this->sessionIdentityRevision
            && $this->membershipRevision === $this->sessionMembershipRevision
            && $this->organizationRevision === $this->sessionOrganizationRevision;
    }
}
