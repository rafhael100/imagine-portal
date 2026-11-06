<?php

namespace App\Domain\PerformanceOnboarding;

use App\Domain\Catalog\PerformanceChannel;
use InvalidArgumentException;

/**
 * Only a fabricated reference. No account ID from Google/Meta, OAuth token,
 * cookie, password, pixel, actual advertiser or payment method.
 */
final readonly class DemoAdAccount
{
    public function __construct(
        public PerformanceChannel $channel,
        public string $reference,
    ) {
        if (preg_match('/\Ademo_[a-z0-9][a-z0-9_-]{0,63}\z/D', $reference) !== 1) {
            throw new InvalidArgumentException('Advertising account references must be synthetic demo values.');
        }
    }
}
