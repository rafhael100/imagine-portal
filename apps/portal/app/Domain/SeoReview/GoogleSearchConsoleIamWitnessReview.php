<?php

declare(strict_types=1);

namespace App\Domain\SeoReview;

/**
 * F133 database diagnostic only. A true result means the specific rows
 * matched in a transaction, NOT that the Portal principal is authenticated
 * for an HTTP request or that Google verified the property.
 */
final readonly class GoogleSearchConsoleIamWitnessReview
{
    private function __construct(public bool $syntheticDatabaseRowsMatch)
    {
    }

    public static function blocked(): self
    {
        return new self(false);
    }

    public static function syntheticDatabaseWitness(): self
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

    public function canIssueOrStoreTokens(): bool
    {
        return false;
    }
}
