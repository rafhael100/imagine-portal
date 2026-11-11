<?php

declare(strict_types=1);

namespace App\Domain\CommercialDeliveryReview;

/**
 * Non-operational output: synthetic eligibility is never access authority.
 * A reviewed, transactional Portal issuer and resource owner are absent.
 */
final readonly class CommercialDeliveryReviewVerdict
{
    private function __construct(public bool $syntheticChecksPass)
    {
    }

    public static function blocked(): self
    {
        return new self(false);
    }

    public static function syntheticCandidate(): self
    {
        return new self(true);
    }

    public function canReadReviewQueue(): bool
    {
        return false;
    }

    public function canReleaseRetry(): bool
    {
        return false;
    }

    public function canAcknowledgeVisitor(): bool
    {
        return false;
    }

    public function canAuthorizeHttp(): bool
    {
        return false;
    }
}
