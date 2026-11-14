<?php

declare(strict_types=1);

namespace App\Domain\SitesIntegration;

/**
 * Lookup by trusted Asaas ACCOUNT AND payment, not by the webhook payload.
 * The production implementation must use unique immutable account/payment
 * bindings to the authenticated tenant, versioned order and installation.
 *
 * No webhook may create or alter a financial binding.
 */
interface AsaasTrustedEventPaymentSource
{
    public function findInstallationByAccountAndPaymentId(
        string $accountRef, string $paymentId
    ): ?AsaasTrustedPaymentBinding;
}
