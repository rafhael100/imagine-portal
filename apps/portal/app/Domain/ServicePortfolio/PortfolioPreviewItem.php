<?php

declare(strict_types=1);

namespace App\Domain\ServicePortfolio;

use InvalidArgumentException;

/**
 * Fixed, immutable, in-memory display information only.
 * No monetary values, subscription IDs, tokens or customer status.
 */
final readonly class PortfolioPreviewItem
{
    public function __construct(
        public PortfolioProduct $product,
        public string $description,
    ) {
        if (trim($description) === '' || strlen($description) > 500
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $description) === 1) {
            throw new InvalidArgumentException('Invalid fictional portfolio description.');
        }
    }

    public function statusLabel(): string
    {
        return 'Não contratável';
    }

    public function statusDetail(): string
    {
        return $this->product->kind()->label();
    }

    public function isCheckoutEnabled(): bool
    {
        return false;
    }

    public function grantsProductAccess(): bool
    {
        return false;
    }

    public function provesServiceEligibility(): bool
    {
        return false;
    }

    public function claimsActiveSubscription(): bool
    {
        return false;
    }
}
