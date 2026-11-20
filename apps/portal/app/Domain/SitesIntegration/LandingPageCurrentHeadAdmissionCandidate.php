<?php
declare(strict_types=1);
namespace App\Domain\SitesIntegration;

use RuntimeException;
use Throwable;

/**
 * Read-only candidate for Landing Page pre-head admission, not an HTTP endpoint.
 * It checks tenant, order and product using independent Portal sources and a
 * service witness. The source itself MUST enforce site/tenant authorization.
 *
 * The result is not client approval, current briefing, payment, publication,
 * acceptance, or a transactional read. Official providers are not installed.
 */
final readonly class LandingPageCurrentHeadAdmissionCandidate
{
    public function __construct(
        private PortalLandingPageHeadServiceWitness $service =
            new DenyingPortalLandingPageHeadServiceWitness(),
        private PortalSiteContractSource $contracts =
            new DenyingPortalSiteContractSource(),
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
            $before = $this->contracts->findAuthorizedSiteOrder($order);
            if (!self::matches($before, $tenant, $order)) {
                throw new RuntimeException();
            }
            // Detect changed service entitlement or order projection; this is
            // NOT a database transaction and cannot establish brief freshness.
            if (!$this->service->permits($verifiedPeerServiceRef, $tenant, $order, $site)) {
                throw new RuntimeException();
            }
            $after = $this->contracts->findAuthorizedSiteOrder($order);
            if (!self::matches($after, $tenant, $order) || $after != $before) {
                throw new RuntimeException();
            }
            return [
                'tenantRef' => $tenant,
                'orderRef' => $order,
                'siteRef' => $site,
                'contractRef' => $before->contractRef,
                'contractRevision' => $before->contractRevision,
                'productCode' => 'landing_page',
            ];
        } catch (Throwable) {
            throw new RuntimeException('PORTAL_LANDING_PAGE_HEAD_ADMISSION_DENIED');
        }
    }

    private static function matches(?SiteContractSnapshot $contract, string $tenant, string $order): bool
    {
        return $contract instanceof SiteContractSnapshot &&
            $contract->productCode === 'landing_page' &&
            $contract->tenantRef === $tenant &&
            $contract->orderRef === $order;
    }
}
