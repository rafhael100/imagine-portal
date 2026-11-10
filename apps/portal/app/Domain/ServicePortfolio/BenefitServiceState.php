<?php

declare(strict_types=1);

namespace App\Domain\ServicePortfolio;

/** Fictional service-lifecycle vocabulary; not a paid contract or real DB state. */
enum BenefitServiceState: string
{
    case Preparing = 'preparing';
    case ActiveIllustration = 'active_illustration';
    case Suspended = 'suspended';
    case Cancelled = 'cancelled';
}
