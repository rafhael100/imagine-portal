<?php

namespace App\Domain\Identity;

use InvalidArgumentException;

/**
 * Opaque identifier used only inside the Portal domain (not a CRM identity).
 * This is a pure value object, not an authentication credential.
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
