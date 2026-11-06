<?php

namespace App\Domain\Organizations;

/**
 * Descriptive role only. It never grants a permission on its own in B-A.
 * Any real role-to-permission policy requires separate security approval.
 */
enum OrganizationRole: string
{
    case Owner = 'owner';
    case Manager = 'manager';
    case Member = 'member';
}
