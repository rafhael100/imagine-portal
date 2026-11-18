<?php

declare(strict_types=1);

namespace App\Domain\SitesIntegration;

/**
 * Production implementation MUST use the authenticated tenant-scoped order,
 * offer-version, contract revision and a unique, immutable Asaas payment ID.
 * Never derive expected amount/tenant/product from a webhook or PSP response.
 */
interface AsaasTrustedPaymentBindingSource
{
    public function findInstallationPayment(string $orderRef): ?AsaasTrustedPaymentBinding;
}
