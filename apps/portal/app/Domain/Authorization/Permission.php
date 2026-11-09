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
    // Nao concedidas por role. Exigem membership e escopo verificados no Portal.
    case CommercialEditCatalog = 'commercial.catalog.edit';
    case CommercialApproveCatalog = 'commercial.catalog.approve';
    case CommercialReservePublish = 'commercial.catalog.publish';
}
