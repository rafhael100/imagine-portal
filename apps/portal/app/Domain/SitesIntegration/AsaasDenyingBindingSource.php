<?php

declare(strict_types=1);

namespace App\Domain\SitesIntegration;

final class AsaasDenyingBindingSource implements AsaasTrustedPaymentBindingSource
{
    public function findInstallationPayment(string $orderRef): ?AsaasTrustedPaymentBinding
    {
        return null;
    }
}
