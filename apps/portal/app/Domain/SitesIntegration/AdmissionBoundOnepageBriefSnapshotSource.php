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
        // Like the export gate, contain accidental output from trusted adapters.
        // This is not a sandbox for an adapter that deliberately flushes buffers.
        $outputLevel = ob_get_level();
        if (!ob_start()) {
            return null;
        }
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
            if (!self::sameContract($contract, $current)) {
                return null;
            }
            $candidate = $this->source->readExact($orderRef, $siteRef, $sourceVersion);
            if (!$candidate instanceof ApprovedOnepageBriefSnapshot ||
                !self::sameContract($contract, $candidate->contract) ||
                ($candidate->selection['siteRef'] ?? null) !== $siteRef ||
                ($candidate->selection['sourceVersion'] ?? null) !== $sourceVersion ||
                !hash_equals($candidate->sourceSha256, hash('sha256', $candidate->sourceBytes))) {
                return null;
            }

            // Never pass a candidate to the caller on a stale receipt binding.
            // Reuse the exact independent reader, then recheck native authority.
            // These observations do not prove a distributed atomic snapshot.
            // Native authority may have disappeared while private bytes were
            // read. Stop before another independent receipt lookup as well.
            if (!self::sameContract($contract, $this->contracts->findAuthorizedSiteOrder($orderRef))) {
                return null;
            }
            $after = ($this->readAdmittedSite)($contract);
            if (!is_string($after) || !hash_equals($after, $siteRef) ||
                !self::sameContract($contract, $this->contracts->findAuthorizedSiteOrder($orderRef))) {
                return null;
            }
            return $candidate;
        } catch (Throwable) {
            return null;
        } finally {
            // Preserve any caller-owned buffer, also when a dependency throws.
            while (ob_get_level() > $outputLevel) {
                if (!ob_end_clean()) break;
            }
        }
    }

    private static function sameContract(SiteContractSnapshot $expected, ?SiteContractSnapshot $actual): bool
    {
        return $actual instanceof SiteContractSnapshot &&
            $actual->tenantRef === $expected->tenantRef &&
            $actual->orderRef === $expected->orderRef &&
            $actual->contractRef === $expected->contractRef &&
            $actual->contractRevision === $expected->contractRevision &&
            $actual->productCode === $expected->productCode;
    }
}
