<?php

declare(strict_types=1);

namespace App\Domain\SitesIntegration;

use App\Domain\Commerce\OnepageOrderReader;
use Throwable;

/**
 * Restricts the expected-payment authority to the accepted Onepage entry.
 * Native buyer/session authorization and the explicit Portal organization
 * contract must agree. tenantRef means portal_org_ref, NOT the Sites UUID.
 * This source never asserts settlement, sends a payment or activates a route.
 */
final readonly class OnepageEntryPaymentBindingSource implements AsaasTrustedPaymentBindingSource
{
    public function __construct(
        private OnepageOrderReader $orders,
        #[\SensitiveParameter] private string $businessReference,
        private string $sandboxAccount,
        private PortalSiteContractSource $contracts = new DenyingPortalSiteContractSource(),
        private AsaasTrustedPaymentBindingSource $bindings = new AsaasDenyingBindingSource(),
    ) {}

    public function findInstallationPayment(string $orderRef): ?AsaasTrustedPaymentBinding
    {
        try {
            if (!preg_match('/\A[a-f0-9]{64}\z/D', $this->businessReference)) return null;
            $order = $this->orders->findOwnedAcceptedOrder($this->businessReference, $orderRef);
            if ($order === null || $order->id !== $orderRef) return null;
            $schedule = OnepageEntryPaymentTerms::schedule($order);
            if ($schedule === null) return null;
            $contract = $this->contracts->findAuthorizedSiteOrder($orderRef);
            if ($contract === null || $contract->tenantRef !== $order->companyReference
                || $contract->orderRef !== $order->id || $contract->contractRef !== $order->contractRef
                || $contract->contractRevision !== $order->contractRevision || $contract->productCode !== 'onepage') return null;

            $binding = $this->bindings->findInstallationPayment($orderRef);
            if ($binding === null || $binding->purpose !== 'installation'
                || $binding->tenantRef !== $contract->tenantRef || $binding->orderRef !== $order->id
                || $binding->contractRef !== $order->contractRef || $binding->contractRevision !== $order->contractRevision
                || $binding->productCode !== 'onepage' || $binding->amountCents !== $schedule[0]['amount_cents']) return null;
            $expectedReference = OnepageEntryPaymentTerms::reference($order, $this->sandboxAccount, $binding->customerId);
            return $expectedReference !== null && $binding->externalReference === $expectedReference ? $binding : null;
        } catch (Throwable) {
            return null;
        }
    }
}
