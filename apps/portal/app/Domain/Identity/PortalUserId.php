<?php

namespace App\Domain\Identity;

use InvalidArgumentException;

/**
 * Opaque identifier value for the Portal's future identity domain.
 * NOT a credential, authenticated issuer or proof of Portal-only origin.
 * An external service can supply an identical string, and string equality
 * alone cannot distinguish it from a verified Portal subject. A future
 * Portal-only authenticator must establish provenance before this value
 * is used for authorization. No Portal/CRM SSO is created here.
 */
final readonly class PortalUserId
{
    public function __construct(public string $value)
    {
        if (preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._:-]{0,127}\z/D', $value) !== 1) {
            throw new InvalidArgumentException('Invalid Portal user identifier.');
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
