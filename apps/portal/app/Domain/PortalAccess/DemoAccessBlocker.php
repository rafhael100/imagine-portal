<?php
namespace App\Domain\PortalAccess;

enum DemoAccessBlocker: string
{
    case Missing = 'demo_missing_context';
    case TimeInvalid = 'demo_invalid_clock';
    case Inactive = 'demo_session_inactive';
    case Expired = 'demo_session_expired';
    case Organization = 'demo_organization_mismatch';
    case MfaRequired = 'demo_mfa_required';
    case MfaStale = 'demo_mfa_stale';
    case Permission = 'demo_permission_denied';
}
