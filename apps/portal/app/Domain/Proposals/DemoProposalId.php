<?php

namespace App\Domain\Proposals;

use InvalidArgumentException;

/** Synthetic proposal identifier, not a real order, invoice or contract. */
final readonly class DemoProposalId
{
    public function __construct(public string $value)
    {
        if (preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._:-]{0,127}\z/D', $value) !== 1) {
            throw new InvalidArgumentException('Invalid demonstration proposal ID.');
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
