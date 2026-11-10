<?php

declare(strict_types=1);

namespace App\Domain\Identity\Invitations;

/**
 * Preflight is DIAGNOSTIC ONLY: a positive synthetic evaluation is NEVER
 * authorization, invitation redemption, session issuance or membership grant.
 * Does not contain or return bearer/digest/actor/tenant identifiers.
 */
final readonly class PortalInvitationPreflightVerdict
{
    private function __construct(public bool $syntheticInputsPlausible)
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

    public function canConsumeInvitation(): bool
    {
        return false;
    }

    public function canIssueSession(): bool
    {
        return false;
    }

    public function canAuthorizeHttp(): bool
    {
        return false;
    }
}
