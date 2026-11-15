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
    // F107: explicit, organization-scoped SEO review permission (not an OAuth grant).
    case ViewSeo = 'seo.view';
    case ManageMembers = 'members.manage';
}
