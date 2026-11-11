<?php

declare(strict_types=1);

namespace App\Domain\PortalAccess\Authority;

/**
 * F160: exact vocabulary of the PostgreSQL F113 session_purpose constraint.
 * A typed enum is not proof of issuer, authentication, or database rights.
 * Legacy NULL sessions cannot construct a purpose without an explicit issuer.
 */
enum PortalSessionPurpose: string
{
    case Business = 'business';
    case InvitationOnly = 'invitation_only';
}
