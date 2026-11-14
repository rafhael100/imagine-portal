<?php

declare(strict_types=1);

namespace App\Domain\SitesIntegration;

/**
 * Point-in-time independent GET /v3/payments/{id}; never a webhook cache.
 * An adapter may use an official Asaas sandbox/production account, but this
 * candidate registers no account, client credentials, route or service.
 */
interface AsaasOfficialPaymentReader
{
    /** @return array<string,mixed>|null */
    public function fetchPayment(string $paymentId): ?array;
}
