<?php
declare(strict_types=1);

namespace App\Homologation\Commerce;

use InvalidArgumentException;

/**
 * Synthetic Comercial -> Portal context, never a charge, agreement or price.
 * Channel hints may highlight a field but NEVER autofill a media budget.
 */
final class CustomerPreviewHandoff
{
    private const PRODUCTS = ['onepage','landing-pages','performance'];
    private const CHANNELS = ['google_ads','meta_ads'];

    public static function channel(mixed $product, mixed $channel): ?string
    {
        if ($channel === null) return null;
        if ($product !== 'performance' || !is_string($channel)
            || !in_array($channel, self::CHANNELS, true)) {
            throw new InvalidArgumentException('Unreviewed marketing channel');
        }
        return $channel;
    }

    public static function shopUrl(mixed $product, mixed $channel = null): ?string
    {
        if (!is_string($product) || !in_array($product, self::PRODUCTS, true)) return null;
        $hint = self::channel($product, $channel);
        $url = '/_preview/portal/shop?product='.$product;
        return $hint === null ? $url : $url.'&channel='.$hint;
    }
}
