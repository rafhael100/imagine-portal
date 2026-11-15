<?php

declare(strict_types=1);

namespace App\Domain\ServicePortfolio;

/**
 * Service lifecycle supplied by a FUTURE trusted contracts authority.
 * These labels alone cannot prove payment, ownership or access.
 */
enum UniversityContractStatus: string
{
    case Active = 'active';
    case Preparing = 'preparing';
    case Trial = 'trial';
    case Suspended = 'suspended';
    case Cancelled = 'cancelled';
}
