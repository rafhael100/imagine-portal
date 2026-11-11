<?php

namespace App\Domain\Authorization;

/**
 * Illustrative, closed permission vocabulary.
 * No wildcards, superuser grants or permissions in other Imagine systems.
 */
enum Permission: string
{
    // F126: ungranted permission for future human review; no HTTP capability.
    // Keep the GSC addition at the same position as its independent branch
    // to permit a clean three-way merge without dropping either contract.
    case ViewCommercialDeliveryReview = 'commercial.delivery-review.view';
    case ViewServices = 'services.view';
    case ManageServices = 'services.manage';
    case ViewBilling = 'billing.view';
    case ManageMembers = 'members.manage';
    // GSC-F134: vocabulary only, ungranted and without OAuth access.
    case ConnectGoogleSearchConsole = 'seo.gsc.connect';
}
