<?php

declare(strict_types=1);

namespace App\Domain\ServicePortfolio;

use InvalidArgumentException;

/**
 * Non-authorizing candidate for an approved future SKU policy. Construction
 * alone proves neither commercial approval nor official source provenance.
 *
 * No eligible SKU is embedded in a production binding or default policy.
 */
final readonly class UniversityCommercialPolicySnapshot
{
    /** @param array<string,list<string>> $eligibleSkusByProduct */
    public function __construct(
        public int $revision,
        public array $eligibleSkusByProduct,
        public int $effectiveFromEpoch,
        public int $effectiveUntilEpoch,
        public int $observedAtEpoch,
        public int $expiresAtEpoch,
    ) {
        if ($revision < 1
            || $effectiveFromEpoch <= 0
            || $effectiveUntilEpoch <= $effectiveFromEpoch
            || $observedAtEpoch <= 0
            || $expiresAtEpoch <= $observedAtEpoch
            || count($eligibleSkusByProduct) > 4
        ) {
            throw new InvalidArgumentException('Invalid university commercial policy snapshot.');
        }

        foreach ($eligibleSkusByProduct as $product => $skus) {
            if (!is_string($product)
                || ($parsed = PortfolioProduct::tryFrom($product)) === null
                || $parsed === PortfolioProduct::UniversidadeImagine
                || !is_array($skus)
                || !array_is_list($skus)
                || count($skus) > 20) {
                throw new InvalidArgumentException('Invalid university SKU policy product.');
            }

            $seen = [];
            foreach ($skus as $sku) {
                if (!is_string($sku)
                    || preg_match('/\A[a-z0-9][a-z0-9._:-]{0,63}\z/D', $sku) !== 1
                    || isset($seen[$sku])) {
                    throw new InvalidArgumentException('Invalid or duplicate eligible SKU.');
                }
                $seen[$sku] = true;
            }
        }
    }

    public function includes(PortfolioProduct $product, string $sku): bool
    {
        return $product !== PortfolioProduct::UniversidadeImagine
            && in_array($sku, $this->eligibleSkusByProduct[$product->value] ?? [], true);
    }

    public function mayAuthorizeUniversity(): bool
    {
        return false;
    }
}
