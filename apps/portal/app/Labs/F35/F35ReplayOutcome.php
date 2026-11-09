<?php

declare(strict_types=1);

namespace App\Labs\F35;

/**
 * F35 lab report ONLY; never an authorization decision.
 * "Completed" means one explicit bounded scan/reconciliation was verified,
 * not that all future revocations are delivered or current in real time.
 */
final readonly class F35ReplayOutcome
{
    public function __construct(
        public bool $completed,
        public string $status,
        public int $discovered,
        public int $verified,
        public int $newAcknowledgements,
        public int $primaryRepairs,
    ) {
    }
}
