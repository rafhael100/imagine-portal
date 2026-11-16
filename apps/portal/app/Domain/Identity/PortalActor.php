<?php

namespace App\Domain\Identity;

/** Domain-only reference; no credentials or session are created. */
final readonly class PortalActor
{
    public function __construct(public PortalUserId $id)
    {
    }
}
