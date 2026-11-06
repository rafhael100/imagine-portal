<?php

namespace App\Domain\Authorization;

/**
 * Generic decisions prevent leaking the existence of another organization.
 * Not an HTTP authorization policy, session or login implementation.
 */
final readonly class AuthorizationDecision
{
    private function __construct(public bool $allowed)
    {
    }

    public static function allow(): self
    {
        return new self(true);
    }

    public static function deny(): self
    {
        return new self(false);
    }
}
