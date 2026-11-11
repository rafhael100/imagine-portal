<?php

declare(strict_types=1);

namespace App\Domain\SeoReview;

/**
 * This is a diagnostic review ONLY, not a security principal or capability.
 * A synthetic-positive verdict must never grant an OAuth code, token, data
 * access or an outbound Google request.
 */
final readonly class GoogleSearchConsoleConnectionReview
{
    private function __construct(public bool $syntheticEligibilityPass)
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

    public function canStartOAuth(): bool
    {
        return false;
    }

    public function canReadGoogle(): bool
    {
        return false;
    }

    public function canStoreTokens(): bool
    {
        return false;
    }
}
