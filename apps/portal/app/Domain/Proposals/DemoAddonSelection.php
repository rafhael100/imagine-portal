<?php

namespace App\Domain\Proposals;

use InvalidArgumentException;

/**
 * Unapproved demonstration-only add-on request.
 * Its code is not a real SKU, approved franchise or entitlement.
 */
final readonly class DemoAddonSelection
{
    public function __construct(public string $code, public int $quantity)
    {
        if (preg_match('/\Ademo_[a-z][a-z0-9_]{0,63}\z/D', $code) !== 1) {
            throw new InvalidArgumentException('Only explicitly synthetic add-on codes are accepted.');
        }

        if ($quantity < 1 || $quantity > 20) {
            throw new InvalidArgumentException('Demonstration add-on quantity must be between 1 and 20.');
        }
    }
}
