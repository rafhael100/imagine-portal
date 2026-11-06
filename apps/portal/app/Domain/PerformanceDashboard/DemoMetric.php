<?php

namespace App\Domain\PerformanceDashboard;

use InvalidArgumentException;

/** Example-only metric; cannot be used as Google/Meta operational evidence. */
final readonly class DemoMetric
{
    public function __construct(public DemoMetricKey $key, public int $value)
    {
        if ($value < 0 || $value > 1000000) {
            throw new InvalidArgumentException('Synthetic metric out of range.');
        }
    }
}
