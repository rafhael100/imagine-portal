<?php

declare(strict_types=1);

namespace App\Domain\ServiceRequests;

/**
 * A business-workflow role, NOT a login, entitlement or authorization grant.
 * Never construct this from an untrusted HTTP field in an operational path.
 */
enum ServiceRequestActorKind: string
{
    case Customer = 'customer';
    case ImagineTeam = 'imagine_team';
}
