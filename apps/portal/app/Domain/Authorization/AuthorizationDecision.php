<?php

namespace App\Domain\Authorization;

/**
 * Generic decisions avoid revealing which authorization predicate failed.
 * A Decision object is not a signed or transferable permission token:
 * the caller must invoke the pure evaluator with trusted server-resolved
 * inputs. Not an HTTP policy, session, login or proof of authorization.
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
