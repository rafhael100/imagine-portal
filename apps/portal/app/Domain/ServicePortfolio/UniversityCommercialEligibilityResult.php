<?php

declare(strict_types=1);

namespace App\Domain\ServicePortfolio;

/** Diagnostic only. It cannot become a PortalGrant or HTTP permission. */
final readonly class UniversityCommercialEligibilityResult
{
    private function __construct(
        public string $reason,
        public int $candidateCount,
    ) {
    }

    public static function blocked(string $reason): self
    {
        return new self($reason, 0);
    }

    public static function inspected(int $count): self
    {
        return new self($count > 0 ? 'candidate_pending_official_authority' : 'no_eligible_service', $count);
    }

    public function mayIssuePortalGrant(): bool
    {
        return false;
    }

    public function provesPaidContract(): bool
    {
        return false;
    }
}
