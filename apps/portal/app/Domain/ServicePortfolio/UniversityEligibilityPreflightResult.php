<?php

declare(strict_types=1);

namespace App\Domain\ServicePortfolio;

/**
 * Diagnostic preflight result, NOT an authenticated entitlement.
 * Neither this result nor a candidate count can issue a Portal grant.
 */
final readonly class UniversityEligibilityPreflightResult
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

    public static function inspected(int $candidateCount): self
    {
        return new self(
            $candidateCount > 0 ? 'contract_candidate' : 'no_eligible_service',
            $candidateCount,
        );
    }

    public function hasCandidate(): bool
    {
        return $this->candidateCount > 0;
    }

    public function mayIssueUniversityGrant(): bool
    {
        return false;
    }

    public function provesRealEligibility(): bool
    {
        return false;
    }

    public function mayExposeCustomerData(): bool
    {
        return false;
    }
}
