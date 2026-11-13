<?php

declare(strict_types=1);

namespace App\Domain\RestoreSafety;

/** A diagnostic from an entirely synthetic rehearsal; never an access token. */
final readonly class LabRestoreFenceVerdict
{
    private function __construct(
        public bool $syntheticChecksPassed,
        public string $reason,
    ) {
    }

    public static function blocked(string $reason): self
    {
        return new self(false, $reason);
    }

    public static function syntheticCandidate(): self
    {
        return new self(true, 'synthetic_recovery_candidate_only');
    }

    public function canAuthorizeHttp(): bool
    {
        return false;
    }

    public function canReleaseProductionServing(): bool
    {
        return false;
    }

    public function canReissueCustomerSessions(): bool
    {
        return false;
    }
}
