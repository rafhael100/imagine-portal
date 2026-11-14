<?php

declare(strict_types=1);

namespace App\Domain\SitesIntegration;

final class AsaasDenyingPaymentReader implements AsaasOfficialPaymentReader
{
    public function fetchPayment(string $paymentId): ?array
    {
        return null;
    }
}
