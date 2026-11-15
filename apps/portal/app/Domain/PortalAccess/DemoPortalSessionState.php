<?php
namespace App\Domain\PortalAccess;

/** Only synthetic states, not an operational Laravel session. */
enum DemoPortalSessionState: string
{
    case Pending = 'demo_pending';
    case Active = 'demo_active';
    case Expired = 'demo_expired';
    case Revoked = 'demo_revoked';
}
