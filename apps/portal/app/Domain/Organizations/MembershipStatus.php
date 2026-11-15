<?php

namespace App\Domain\Organizations;

/** Only Active is potentially authorized. No invitation is issued here. */
enum MembershipStatus: string
{
    case Invited = 'invited';
    case Active = 'active';
    case Suspended = 'suspended';
    case Revoked = 'revoked';
}
