<?php

declare(strict_types=1);

namespace App\Labs\F40;

use InvalidArgumentException;

/** A report on a fake delivery rehearsal, never an access grant. */
final readonly class F40FakeRelayOutcome
{
    private const STATUSES = [
        'complete',
        'source_unavailable',
        'sink_unavailable',
        'ack_pending',
        'bounded_backpressure',
    ];

    public function __construct(
        public bool $completed,
        public string $status,
        public int $discovered,
        public int $recorded,
    ) {
        if (!in_array($status,self::STATUSES,true)
            || $completed !== ($status==='complete')
            || $discovered<0 || $recorded<0 || $recorded>$discovered) {
            throw new InvalidArgumentException('Invalid fake relay result.');
        }
    }

    public function deliveredExternally(): bool
    {
        return false;
    }

    public function authorizesCustomerAccess(): bool
    {
        return false;
    }
}
