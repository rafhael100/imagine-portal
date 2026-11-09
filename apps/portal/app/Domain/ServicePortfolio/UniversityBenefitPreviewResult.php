<?php

declare(strict_types=1);

namespace App\Domain\ServicePortfolio;

use InvalidArgumentException;

/**
 * Bounded non-operational preview. No issuer, lookup or service entitlement.
 */
final readonly class UniversityBenefitPreviewResult
{
    private function __construct(
        public string $reason,
        public int $illustrativeCandidateCount,
    ) {
        if (!in_array($reason, [
            'illustrative_candidate',
            'no_illustrative_candidate',
            'missing_organization',
            'invalid_fixture_input',
            'foreign_organization',
            'duplicate_reference',
        ], true)) {
            throw new InvalidArgumentException('Unknown University preview decision.');
        }
        if ($illustrativeCandidateCount < 0 || $illustrativeCandidateCount > 50
            || ($reason !== 'illustrative_candidate' && $illustrativeCandidateCount !== 0)
            || ($reason === 'illustrative_candidate' && $illustrativeCandidateCount === 0)) {
            throw new InvalidArgumentException('Invalid University preview count.');
        }
    }

    public static function hypothetical(int $candidateCount): self
    {
        return $candidateCount > 0
            ? new self('illustrative_candidate', $candidateCount)
            : new self('no_illustrative_candidate', 0);
    }

    public static function blocked(string $reason): self
    {
        return new self($reason, 0);
    }

    public function showsHypotheticalCandidate(): bool
    {
        return $this->reason === 'illustrative_candidate';
    }

    public function mayGrantUniversityAccess(): bool
    {
        return false;
    }

    public function authorizesStandalonePurchase(): bool
    {
        return false;
    }

    public function provesCustomerEligibility(): bool
    {
        return false;
    }

    public function mayExposeCustomerData(): bool
    {
        return false;
    }
}
