<?php

namespace App\Domain\Authorization;

/**
 * Illustrative, closed permission vocabulary.
 * No wildcards, superuser grants or permissions in other Imagine systems.
 */
enum Permission: string
{
    case ViewServices = 'services.view';
    case ManageServices = 'services.manage';
    case ViewBilling = 'billing.view';
    case ManageMembers = 'members.manage';
    // F126: ungranted permission for future human review; no HTTP capability.
    case ViewCommercialDeliveryReview = 'commercial.delivery-review.view';
}
