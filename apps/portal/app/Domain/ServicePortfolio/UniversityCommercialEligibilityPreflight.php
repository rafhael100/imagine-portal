<?php

declare(strict_types=1);

namespace App\Domain\ServicePortfolio;

use App\Domain\Organizations\OrganizationId;
use Throwable;

/**
 * Future four-source reconciliation: current contracts, independent
 * revocation, approved commercial SKU policy and current coverage period.
 *
 * ALL sources are deliberately unavailable by default. Even a successful
 * synthetic comparison is NOT authorization, active payment evidence, SSO,
 * a Portal session or permission to serve any customer.
 */
final readonly class UniversityCommercialEligibilityPreflight
{
    public function __construct(
        private UniversityContractAuthority $contracts = new UnavailableUniversityContractAuthority(),
        private UniversityServiceRevisionAuthority $revisions = new UnavailableUniversityServiceRevisionAuthority(),
        private UniversityCommercialPolicyAuthority $policies = new UnavailableUniversityCommercialPolicyAuthority(),
        private UniversityServiceCoverageAuthority $coverage = new UnavailableUniversityServiceCoverageAuthority(),
        // Bound combined source latency even when each snapshot is individually fresh.
        private int $maxSourceReadMs = 1500,
    ) {
        if ($maxSourceReadMs < 1 || $maxSourceReadMs > 5000) {
            throw new \InvalidArgumentException('Invalid university source-read budget.');
        }
    }

    public function inspect(
        ?OrganizationId $organization,
        int $nowEpoch,
    ): UniversityCommercialEligibilityResult {
        if ($organization === null || $nowEpoch <= 0 || $nowEpoch >= PHP_INT_MAX - 6) {
            return UniversityCommercialEligibilityResult::blocked('invalid_context');
        }

        // University schema has max 80 bytes, while generic Portal IDs can
        // be longer. NEVER truncate or map tenant identifiers implicitly.
        if (!UniversityInteropIdentifiers::supportsOrganization($organization)) {
            return UniversityCommercialEligibilityResult::blocked('unsupported_tenant_identifier');
        }

        // Time-box the FOUR source reads together. One slow provider must
        // never yield a seemingly-current composite snapshot after a long
        // pause. This is diagnostic only; a future operational adapter must
        // also enforce per-request network timeouts and atomic revocation.
        $startedAt = hrtime(true);
        try {
            $records = $this->contracts->currentFor($organization);
            $revisions = $this->revisions->currentFor($organization);
            $policy = $this->policies->currentFor($organization);
            $coverage = $this->coverage->currentFor($organization);
        } catch (Throwable) {
            return UniversityCommercialEligibilityResult::blocked('source_unavailable');
        }

        $elapsedNs = hrtime(true) - $startedAt;
        if ($elapsedNs > $this->maxSourceReadMs * 1_000_000) {
            return UniversityCommercialEligibilityResult::blocked('source_read_timeout');
        }

        // The original epoch was sampled BEFORE four potentially slow source
        // reads. At end-of-read, treat even a partial elapsed second as a
        // whole second for expiration checks. Never extend snapshot TTLs.
        $checkedAtEpoch = $nowEpoch + intdiv($elapsedNs + 999_999_999, 1_000_000_000);

        if ($records === null || $revisions === null || $policy === null || $coverage === null) {
            return UniversityCommercialEligibilityResult::blocked('source_unavailable');
        }

        if (!is_array($records) || !array_is_list($records) || count($records) > 50
            || !is_array($coverage) || !array_is_list($coverage) || count($coverage) > 50
        ) {
            return UniversityCommercialEligibilityResult::blocked('invalid_source');
        }

        if ($policy->observedAtEpoch > $nowEpoch
            || $policy->observedAtEpoch < $nowEpoch - 60
            || $policy->expiresAtEpoch <= $checkedAtEpoch
            || $policy->expiresAtEpoch > $nowEpoch + 60
            || $policy->effectiveFromEpoch > $nowEpoch
            || $policy->effectiveUntilEpoch <= $checkedAtEpoch
        ) {
            return UniversityCommercialEligibilityResult::blocked('stale_or_inactive_policy');
        }

        if ($revisions->expiresAtEpoch <= $checkedAtEpoch) {
            return UniversityCommercialEligibilityResult::blocked('revocation_expired_during_read');
        }

        // Preserve read-once semantics: original providers must not be
        // queried again after acquiring contract and revocation snapshots.
        $contracts = new class($records) implements UniversityContractAuthority {
            public function __construct(private array $records)
            {
            }

            public function currentFor(OrganizationId $organization): ?array
            {
                return $this->records;
            }
        };
        $revocation = new class($revisions) implements UniversityServiceRevisionAuthority {
            public function __construct(private UniversityServiceRevisionSnapshot $snapshot)
            {
            }

            public function currentFor(OrganizationId $organization): ?UniversityServiceRevisionSnapshot
            {
                return $this->snapshot;
            }
        };

        $base = (new UniversityRevocationPreflight($contracts, $revocation))
            ->inspect($organization, $nowEpoch);
        if ($base->reason !== 'candidate_pending_authority') {
            return UniversityCommercialEligibilityResult::blocked('contract_'.$base->reason);
        }

        $active = [];
        foreach ($records as $record) {
            if (!$record instanceof UniversityContractSnapshot) {
                return UniversityCommercialEligibilityResult::blocked('invalid_contract_source');
            }
            if ($record->expiresAtEpoch <= $checkedAtEpoch) {
                return UniversityCommercialEligibilityResult::blocked('contract_expired_during_read');
            }
            if ($record->isQualifyingCandidate()) {
                $active[$record->serviceReference] = $record;
            }
        }

        $seen = [];
        foreach ($coverage as $item) {
            if (!$item instanceof UniversityServiceCoverageSnapshot) {
                return UniversityCommercialEligibilityResult::blocked('invalid_coverage_source');
            }

            if (!$item->organizationId->equals($organization)) {
                return UniversityCommercialEligibilityResult::blocked('wrong_coverage_tenant');
            }

            if (isset($seen[$item->serviceReference])
                || !isset($active[$item->serviceReference])) {
                return UniversityCommercialEligibilityResult::blocked('duplicate_or_extra_coverage');
            }
            $seen[$item->serviceReference] = true;
            $contract = $active[$item->serviceReference];

            if ($item->product !== $contract->product
                || $item->contractRevision !== $contract->sourceRevision
                || $item->policyRevision !== $policy->revision
            ) {
                return UniversityCommercialEligibilityResult::blocked('contract_policy_revision_mismatch');
            }

            if ($item->observedAtEpoch > $nowEpoch
                || $item->observedAtEpoch < $nowEpoch - 60
                || $item->expiresAtEpoch <= $checkedAtEpoch
                || $item->expiresAtEpoch > $nowEpoch + 60
                || $item->effectiveFromEpoch > $nowEpoch
                || $item->effectiveUntilEpoch <= $checkedAtEpoch
            ) {
                return UniversityCommercialEligibilityResult::blocked('stale_or_expired_coverage');
            }

            // Settlement of a site installation, a webhook event or a
            // caller-constructed/legacy snapshot is NOT current service
            // coverage. The two candidate bases below still need official
            // contract/payment/grace provenance and independent approval.
            if (!$item->basis->isCurrentServiceCandidate()) {
                return UniversityCommercialEligibilityResult::blocked('coverage_not_authoritative');
            }

            if (!$policy->includes($item->product, $item->planSku)) {
                return UniversityCommercialEligibilityResult::blocked('sku_not_approved');
            }
        }

        if (count($seen) !== count($active)
            || $base->candidateCount !== count($active)) {
            return UniversityCommercialEligibilityResult::blocked('missing_active_service_coverage');
        }

        return UniversityCommercialEligibilityResult::inspected(count($active));
    }
}
