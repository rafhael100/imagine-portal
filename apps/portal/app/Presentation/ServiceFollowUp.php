<?php

declare(strict_types=1);

namespace App\Presentation;

use InvalidArgumentException;

/** A fictional display record, never a service entitlement or SLA. */
final readonly class ServiceFollowUp
{
    public function __construct(
        public string $reference,
        public string $product,
        public JourneyState $state,
        public string $summary,
        public string $nextUpdate,
    ) {
        if (preg_match('/\ADEMO-SVC-[A-Z0-9-]{1,40}\z/D', $reference) !== 1) {
            throw new InvalidArgumentException('Expected a fictional service reference.');
        }
        foreach ([$product, $summary, $nextUpdate] as $text) {
            if (trim($text) === '' || strlen($text) > 2000) {
                throw new InvalidArgumentException('Expected bounded presentation text.');
            }
        }
    }
}
