<?php

namespace App\Domain\Authorization;

/**
 * Illustrative, closed permission vocabulary.
 * No wildcards, superuser grants or permissions in other Imagine systems.
 */
enum Permission: string
{
    // F126: ungranted permission for future human review; no HTTP capability.
    // The GSC permission below is also vocabulary-only, without OAuth access.
    // Keep the GSC addition at the same position as its independent branch
    // to permit a clean three-way merge without dropping either contract.
    case ViewCommercialDeliveryReview = 'commercial.delivery-review.view';
    case ViewServices = 'services.view';
    case ManageServices = 'services.manage';
    case ViewBilling = 'billing.view';
    case ManageMembers = 'members.manage';
    case ConnectGoogleSearchConsole = 'seo.gsc.connect';
}
