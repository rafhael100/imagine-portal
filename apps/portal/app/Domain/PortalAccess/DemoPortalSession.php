<?php
namespace App\Domain\PortalAccess;

use App\Domain\Identity\PortalUserId;
use App\Domain\Organizations\OrganizationId;
use InvalidArgumentException;

/**
 * UNTRUSTED, caller-constructible, test-only fixture. Neither identity
 * provenance nor MFA/session integrity is proven by these fields.
 */
final readonly class DemoPortalSession
{
    public function __construct(
        public string $label,
        public PortalUserId $actor,
        public OrganizationId $organization,
        public DemoPortalSessionState $state,
        public int $issuedAt,
        public int $expiresAt,
        public ?int $mfaAt,
    ) {
        if (preg_match('/\Ademo_session_[a-z0-9_-]{1,48}\z/D', $label) !== 1
            || $issuedAt <= 0 || $expiresAt <= $issuedAt
            || ($mfaAt !== null && $mfaAt <= 0)) {
            throw new InvalidArgumentException('Invalid synthetic session fields.');
        }
    }

    public function isAuthenticationEvidence(): bool
    {
        return false;
    }
}
