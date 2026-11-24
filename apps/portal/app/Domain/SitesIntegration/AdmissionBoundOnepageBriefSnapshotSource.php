<?php

declare(strict_types=1);

namespace App\Domain\SitesIntegration;

use Closure;
use Throwable;

/**
 * Candidate composition only: limit a private briefing source to the exact
 * admitted site of a currently authenticated Onepage contract.
 *
 * The receipt reader is a trusted dependency, never a request field or a
 * receipt returned by the admission writer. A receipt is historical identity,
 * not evidence of payment, briefing approval, acceptance or publication.
 * No operational binding is installed by this class.
 */
final readonly class AdmissionBoundOnepageBriefSnapshotSource implements ApprovedOnepageBriefSnapshotSource
{
    /**
     * @param Closure(SiteContractSnapshot): ?string $readAdmittedSite
     * Independent exact receipt reader, scoped to this buyer and command.
     */
    public function __construct(
        private PortalSiteContractSource $contracts,
        private Closure $readAdmittedSite,
        private ApprovedOnepageBriefSnapshotSource $source = new DenyingApprovedOnepageBriefSnapshotSource(),
    ) {}

    public function readExact(string $orderRef, string $siteRef, int $sourceVersion): ?ApprovedOnepageBriefSnapshot
    {
        try {
            if (PHP_INT_SIZE !== 8 ||
                preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._:-]{0,127}\z/D', $orderRef) !== 1 ||
                preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/D', $siteRef) !== 1 ||
                $sourceVersion < 0 || $sourceVersion > 9007199254740991) {
                return null;
            }
            $contract = $this->contracts->findAuthorizedSiteOrder($orderRef);
            if (!$contract instanceof SiteContractSnapshot ||
                $contract->productCode !== 'onepage' || $contract->orderRef !== $orderRef) {
                return null;
            }
            $admitted = ($this->readAdmittedSite)($contract);
            if (!is_string($admitted) || !hash_equals($admitted, $siteRef)) {
                return null;
            }
            // The independent read can take time. Recheck the native buyer's
            // contract before allowing even a private briefing read.
            $current = $this->contracts->findAuthorizedSiteOrder($orderRef);
            if (!$current instanceof SiteContractSnapshot ||
                $current->tenantRef !== $contract->tenantRef ||
                $current->orderRef !== $contract->orderRef ||
                $current->contractRef !== $contract->contractRef ||
                $current->contractRevision !== $contract->contractRevision ||
                $current->productCode !== $contract->productCode) {
                return null;
            }
            return $this->source->readExact($orderRef, $siteRef, $sourceVersion);
        } catch (Throwable) {
            return null;
        }
    }
}
