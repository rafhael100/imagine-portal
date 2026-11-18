<?php

declare(strict_types=1);

namespace App\Infrastructure\Commerce;

use App\Domain\Commerce\OnepageOrderReader;
use App\Domain\SitesIntegration\PortalSiteContractSource;
use App\Domain\SitesIntegration\SiteContractSnapshot;
use SensitiveParameter;
use Throwable;

/**
 * Projects the current native buyer-owned accepted contract into CreateProject v1.
 * tenantRef is the Portal organization reference, never the Sites internal UUID.
 * Payment, production, client acceptance and publication remain separate checks.
 */
final readonly class PostgresPortalSiteContractSource implements PortalSiteContractSource
{
    public function __construct(
        private OnepageOrderReader $orders,
        #[SensitiveParameter] private string $businessReference,
    ) {}

    public function findAuthorizedSiteOrder(string $orderRef): ?SiteContractSnapshot
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $this->businessReference) !== 1
            || preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/D', $orderRef) !== 1) return null;
        try {
            // Native SQL rechecks the session, buyer, company, MFA and current
            // publication/revocation state on every read, including a retry.
            $order = $this->orders->findOwnedAcceptedOrder($this->businessReference, $orderRef);
            if ($order === null || !hash_equals($orderRef, $order->id)) return null;
            return new SiteContractSnapshot($order->companyReference, $order->id,
                $order->contractRef, $order->contractRevision, 'onepage');
        } catch (Throwable) {
            return null;
        }
    }
}
