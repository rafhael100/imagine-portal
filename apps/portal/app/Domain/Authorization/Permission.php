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
    case ConnectGoogleSearchConsole = 'seo.gsc.connect';
}
