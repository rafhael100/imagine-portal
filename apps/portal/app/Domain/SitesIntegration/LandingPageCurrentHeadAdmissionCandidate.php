<?php
declare(strict_types=1);
namespace App\Domain\SitesIntegration;
use RuntimeException;
use Throwable;

/**
 * Default-denied Landing admission. An order-level contract never grants site ownership.
 * The independent Portal site binding must match exact tenant/order/site/contract/revision.
 * Offline candidate only: no official provider, mTLS route, transaction, approval or publication.
 */
final readonly class LandingPageCurrentHeadAdmissionCandidate
{
    public function __construct(
        private PortalLandingPageHeadServiceWitness $service = new DenyingPortalLandingPageHeadServiceWitness(),
        private PortalSiteContractSource $contracts = new DenyingPortalSiteContractSource(),
        private PortalLandingPageSiteOrderBindingSource $bindings = new DenyingPortalLandingPageSiteOrderBindingSource(),
    ) {}

    /** @return array{tenantRef:string,orderRef:string,siteRef:string,contractRef:string,contractRevision:int,productCode:string} */
    public function inspect(string $requestBytes, string $verifiedPeerServiceRef): array
    {
        try {
            if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $verifiedPeerServiceRef) !== 1) {
                throw new RuntimeException();
            }
            $selection = LandingPageCurrentHeadS2sRequestCandidate::decode($requestBytes);
            $tenant = $selection['tenantRef'];
            $order = $selection['orderRef'];
            $site = $selection['siteRef'];
            if (!$this->service->permits($verifiedPeerServiceRef, $tenant, $order, $site)) {
                throw new RuntimeException();
            }
            $beforeContract = $this->contracts->findAuthorizedSiteOrder($order);
            if (!self::matchesContract($beforeContract, $tenant, $order)) {
                throw new RuntimeException();
            }
            $beforeBinding = $this->bindings->readCurrentBinding($tenant, $order, $site);
            if (!self::matchesBinding($beforeBinding, $beforeContract, $tenant, $order, $site)) {
                throw new RuntimeException();
            }
            // Rereads detect observed changes; they do NOT create a transaction or defeat ABA.
            if (!$this->service->permits($verifiedPeerServiceRef, $tenant, $order, $site)) {
                throw new RuntimeException();
            }
            $afterContract = $this->contracts->findAuthorizedSiteOrder($order);
            if (!self::matchesContract($afterContract, $tenant, $order) ||
                $afterContract != $beforeContract) {
                throw new RuntimeException();
            }
            $afterBinding = $this->bindings->readCurrentBinding($tenant, $order, $site);
            if (!self::matchesBinding($afterBinding, $afterContract, $tenant, $order, $site) ||
                $afterBinding != $beforeBinding) {
                throw new RuntimeException();
            }
            return [
                'tenantRef' => $tenant,
                'orderRef' => $order,
                'siteRef' => $site,
                'contractRef' => $beforeContract->contractRef,
                'contractRevision' => $beforeContract->contractRevision,
                'productCode' => 'landing_page',
            ];
        } catch (Throwable) {
            throw new RuntimeException('PORTAL_LANDING_PAGE_HEAD_ADMISSION_DENIED');
        }
    }

    private static function matchesContract(?SiteContractSnapshot $value, string $tenant, string $order): bool
    {
        return $value instanceof SiteContractSnapshot &&
            $value->tenantRef === $tenant &&
            $value->orderRef === $order &&
            $value->productCode === 'landing_page';
    }

    private static function matchesBinding(
        ?LandingPageSiteOrderBinding $binding, SiteContractSnapshot $contract,
        string $tenant, string $order, string $site,
    ): bool {
        return $binding instanceof LandingPageSiteOrderBinding &&
            $binding->tenantRef === $tenant && $binding->orderRef === $order &&
            $binding->siteRef === $site && $binding->productCode === 'landing_page' &&
            $binding->contractRef === $contract->contractRef &&
            $binding->contractRevision === $contract->contractRevision;
    }
}
