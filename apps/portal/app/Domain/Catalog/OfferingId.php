<?php

namespace App\Domain\Catalog;

use InvalidArgumentException;

/** Opaque, immutable offering identifier; never an organization or CRM ID. */
final readonly class OfferingId
{
    public function __construct(public string $value)
    {
        if (preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._:-]{0,127}\z/D', $value) !== 1) {
            throw new InvalidArgumentException('Invalid offering identifier.');
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
