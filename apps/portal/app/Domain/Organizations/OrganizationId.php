<?php

namespace App\Domain\Organizations;

use InvalidArgumentException;

/** Opaque reference to an organization in the Portal, not to a CRM account. */
final readonly class OrganizationId
{
    public function __construct(public string $value)
    {
        if (preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._:-]{0,127}\z/D', $value) !== 1) {
            throw new InvalidArgumentException('Invalid Portal organization identifier.');
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
