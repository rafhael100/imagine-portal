<?php

declare(strict_types=1);

namespace App\Domain\SitesIntegration;

final class DenyingAsaasEventPaymentSource implements AsaasTrustedEventPaymentSource
{
    public function findInstallationByAccountAndPaymentId(
        string $accountRef, string $paymentId
    ): ?AsaasTrustedPaymentBinding {
        return null;
    }
}
