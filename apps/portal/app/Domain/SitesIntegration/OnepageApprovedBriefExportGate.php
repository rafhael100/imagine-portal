<?php

declare(strict_types=1);

namespace App\Domain\SitesIntegration;

use RuntimeException;
use Throwable;

/**
 * Fail-closed boundary for a future *official* Onepage briefing producer.
 *
 * No source/approval adapter is installed. The returned candidate still
 * carries false authority flags. A pair of reads does not prove atomicity;
 * any operational transport must revalidate under its own reviewed gate.
 */
final readonly class OnepageApprovedBriefExportGate
{
    public function __construct(
        private PortalSiteContractSource $contracts = new DenyingPortalSiteContractSource(),
        private ApprovedOnepageBriefSnapshotSource $briefs = new DenyingApprovedOnepageBriefSnapshotSource(),
        private CurrentOnepageBriefApprovalWitness $approvals = new DenyingCurrentOnepageBriefApprovalWitness(),
    ) {}

    /**
     * Read one explicit order/site/source version. Never choose "latest".
     * Original source bytes are obtained ONLY through the injected approved
     * reader, not caller input. The candidate exporter does not create power.
     */
    public function prepare(string $orderRef, string $siteRef, int $sourceVersion): array
    {
        try {
            if (PHP_INT_SIZE !== 8 ||
                preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $orderRef) !== 1 ||
                preg_match('/\A[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\z/D', $siteRef) !== 1 ||
                $sourceVersion < 0 || $sourceVersion > 9007199254740991) {
                throw new RuntimeException();
            }

            $beforeContract = $this->contracts->findAuthorizedSiteOrder($orderRef);
            // Deny before the private read: a negative contract lookup must
            // never disclose even a read-side effect to the briefing source.
            if (!$beforeContract instanceof SiteContractSnapshot ||
                $beforeContract->productCode !== 'onepage' ||
                $beforeContract->orderRef !== $orderRef) {
                throw new RuntimeException();
            }
            $beforeBrief = $this->briefs->readExact($orderRef, $siteRef, $sourceVersion);
            if (!$beforeContract instanceof SiteContractSnapshot ||
                !$beforeBrief instanceof ApprovedOnepageBriefSnapshot ||
                !self::matches($beforeContract, $beforeBrief, $orderRef, $siteRef, $sourceVersion) ||
                !$this->approvals->isCurrent($beforeBrief)) {
                throw new RuntimeException();
            }
            $export = OnepageBriefSnapshotCandidateEncoder::build(
                $beforeContract, $beforeBrief->selection, $beforeBrief->sourceBytes,
            );
            if ($export['payloadSha256'] !== $beforeBrief->sourceSha256 ||
                $export['authorityVerified'] !== false ||
                $export['clientAccepted'] !== false ||
                $export['canPublish'] !== false ||
                $export['canAcceptRealOrders'] !== false) {
                throw new RuntimeException();
            }

            // Detect a changed contract, brief, approval or revocation during
            // construction. Not a lock and not portable commercial authority.
            $afterContract = $this->contracts->findAuthorizedSiteOrder($orderRef);
            // Revocation, tenant/order change, or product drift cancels the
            // second private read too. A closed final result is not enough.
            if (!$afterContract instanceof SiteContractSnapshot ||
                $afterContract != $beforeContract ||
                $afterContract->productCode !== 'onepage' ||
                $afterContract->orderRef !== $orderRef) {
                throw new RuntimeException();
            }
            $afterBrief = $this->briefs->readExact($orderRef, $siteRef, $sourceVersion);
            if (!$afterBrief instanceof ApprovedOnepageBriefSnapshot ||
                $afterBrief != $beforeBrief ||
                !$this->approvals->isCurrent($afterBrief) ||
                !self::matches($afterContract, $afterBrief, $orderRef, $siteRef, $sourceVersion)) {
                throw new RuntimeException();
            }
            return $export;
        } catch (Throwable) {
            // Never expose order, site, approvalRef, original content or cause.
            throw new RuntimeException('ONEPAGE_APPROVED_BRIEF_EXPORT_DENIED');
        }
    }

    private static function matches(
        SiteContractSnapshot $current,
        ApprovedOnepageBriefSnapshot $record,
        string $order,
        string $site,
        int $version,
    ): bool {
        $selected = $record->selection;
        return $current->productCode === 'onepage' &&
            $current->orderRef === $order &&
            $record->contract->tenantRef === $current->tenantRef &&
            $record->contract->orderRef === $current->orderRef &&
            $record->contract->contractRef === $current->contractRef &&
            $record->contract->contractRevision === $current->contractRevision &&
            $record->contract->productCode === 'onepage' &&
            isset($selected['siteRef'], $selected['sourceVersion']) &&
            $selected['siteRef'] === $site &&
            $selected['sourceVersion'] === $version &&
            $record->sourceSha256 === hash('sha256', $record->sourceBytes);
    }
}
