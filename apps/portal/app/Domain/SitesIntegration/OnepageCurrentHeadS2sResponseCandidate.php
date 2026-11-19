<?php

declare(strict_types=1);

namespace App\Domain\SitesIntegration;

use RuntimeException;
use Throwable;

/**
 * Unbound Portal-side response composer for the Sites mTLS current-head
 * candidate. It NEVER acts as an HTTP controller or authenticates a user.
 * The future route must obtain the service identity from VERIFIED mTLS and
 * the official Portal readers must enforce atomic current/revoked state.
 * No granting of editorial, payment, publication or customer consent rights.
 */
final readonly class OnepageCurrentHeadS2sResponseCandidate
{
    public function __construct(
        private PortalOnepageHeadServiceWitness $service =
            new DenyingPortalOnepageHeadServiceWitness(),
        private PortalSiteContractSource $contracts =
            new DenyingPortalSiteContractSource(),
        private CurrentApprovedOnepageHeadSource $current =
            new DenyingCurrentApprovedOnepageHeadSource(),
        private ApprovedOnepageBriefSnapshotSource $exact =
            new DenyingApprovedOnepageBriefSnapshotSource(),
        private CurrentOnepageBriefApprovalWitness $approval =
            new DenyingCurrentOnepageBriefApprovalWitness(),
    ) {}

    /**
     * Flat JSON EXACTLY matching Sites' eleven-field current-head reader.
     * No body from the client, no current-head self-claim, no raw source bytes,
     * and no hidden fallback to D0 `briefing_available`.
     */
    public function prepare(
        string $authenticatedServiceRef,
        string $tenantRef,
        string $orderRef,
        string $siteRef,
    ): string {
        try {
            foreach ([$authenticatedServiceRef, $tenantRef, $orderRef] as $ref) {
                if (!is_string($ref) ||
                    preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $ref) !== 1) {
                    throw new RuntimeException();
                }
            }
            if (preg_match('/\A[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\z/D', $siteRef) !== 1 ||
                !$this->service->permits($authenticatedServiceRef, $tenantRef, $orderRef, $siteRef)) {
                throw new RuntimeException();
            }
            $contract = $this->contracts->findAuthorizedSiteOrder($orderRef);
            $head = $this->current->readCurrent($tenantRef, $orderRef, $siteRef);
            if (!$contract instanceof SiteContractSnapshot ||
                !$head instanceof ApprovedOnepageBriefSnapshot ||
                !self::matches($contract, $head, $tenantRef, $orderRef, $siteRef)) {
                throw new RuntimeException();
            }
            $version = $head->selection['sourceVersion'] ?? null;
            if (!is_int($version) || $version < 0 || $version > 9007199254740991) {
                throw new RuntimeException();
            }
            $exact = $this->exact->readExact($orderRef, $siteRef, $version);
            if (!$exact instanceof ApprovedOnepageBriefSnapshot ||
                $exact != $head || !$this->approval->isCurrent($head)) {
                throw new RuntimeException();
            }
            $export = OnepageBriefSnapshotCandidateEncoder::build(
                $contract, $head->selection, $head->sourceBytes,
            );
            if ($export['payloadSha256'] !== $head->sourceSha256 ||
                $export['authorityVerified'] !== false ||
                $export['clientAccepted'] !== false ||
                $export['canPublish'] !== false ||
                $export['canAcceptRealOrders'] !== false) {
                throw new RuntimeException();
            }
            $response = [
                'tenantRef' => $contract->tenantRef,
                'orderRef' => $contract->orderRef,
                'siteRef' => $siteRef,
                'contractRef' => $contract->contractRef,
                'contractRevision' => $contract->contractRevision,
                'productCode' => 'onepage',
                'snapshotRef' => $head->selection['snapshotRef'],
                'briefRevision' => $head->selection['expectedBriefRevision'] + 1,
                'sourceVersion' => $version,
                'manifestSha256' => $export['manifestSha256'],
                'payloadSha256' => $export['payloadSha256'],
            ];
            // Recheck all authorities independently after deterministic export.
            // These reads are NOT a distributed transaction or a server-side
            // CAS; runtime transport must use an official transactional epoch.
            if (!$this->service->permits($authenticatedServiceRef, $tenantRef, $orderRef, $siteRef) ||
                $this->contracts->findAuthorizedSiteOrder($orderRef) != $contract ||
                $this->current->readCurrent($tenantRef, $orderRef, $siteRef) != $head ||
                $this->exact->readExact($orderRef, $siteRef, $version) != $head ||
                !$this->approval->isCurrent($head)) {
                throw new RuntimeException();
            }
            $json = json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (!is_string($json) || strlen($json) > 4096) throw new RuntimeException();
            return $json;
        } catch (Throwable) {
            // No source, identity, approval or contract disclosure.
            throw new RuntimeException('PORTAL_ONEPAGE_CURRENT_HEAD_DENIED');
        }
    }

    private static function matches(
        SiteContractSnapshot $contract,
        ApprovedOnepageBriefSnapshot $head,
        string $tenantRef,
        string $orderRef,
        string $siteRef,
    ): bool {
        return $contract->productCode === 'onepage' &&
            $contract->tenantRef === $tenantRef &&
            $contract->orderRef === $orderRef &&
            $head->contract == $contract &&
            ($head->selection['siteRef'] ?? null) === $siteRef;
    }
}
