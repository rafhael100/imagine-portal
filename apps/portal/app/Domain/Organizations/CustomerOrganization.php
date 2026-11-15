<?php

namespace App\Domain\Organizations;

/** Domain-only organization reference; no persistence or company registration. */
final readonly class CustomerOrganization
{
    public function __construct(public OrganizationId $id)
    {
    }
}
