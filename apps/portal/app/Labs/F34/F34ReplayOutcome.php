<?php

declare(strict_types=1);

namespace App\Labs\F34;

/**
 * F34 runner result is a laboratory process report, NEVER an auth verdict.
 * A partial result MUST NOT be advertised as a completed revocation batch.
 */
final readonly class F34ReplayOutcome
{
    public function __construct(
        public bool $completed,
        public string $status,
        public int $discovered,
        public int $applied,
    ) {
    }
}
