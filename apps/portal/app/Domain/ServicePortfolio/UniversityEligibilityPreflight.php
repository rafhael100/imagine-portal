<?php

declare(strict_types=1);

namespace App\Domain\ServicePortfolio;

use App\Domain\Organizations\OrganizationId;
use Throwable;

/**
 * Read-only, non-operational diagnostics of future active service records.
 * NEVER call this class to authorize HTTP, sessions, grants or user data.
 *
 * Trusted provenance and in-transaction revocation are still unsolved.
 * Default authority returns null; no live data source or I/O is installed.
 */
final readonly class UniversityEligibilityPreflight
{
    public function __construct(
        private UniversityContractAuthority $contracts = new UnavailableUniversityContractAuthority(),
    ) {
    }

    public function inspect(
        ?OrganizationId $organization,
        int $nowEpoch,
    ): UniversityEligibilityPreflightResult {
        if ($organization === null || $nowEpoch <= 0) {
            return UniversityEligibilityPreflightResult::blocked('invalid_context');
        }

        try {
            $snapshots = $this->contracts->currentFor($organization);
        } catch (Throwable) {
            return UniversityEligibilityPreflightResult::blocked('source_unavailable');
        }

        if ($snapshots === null) {
            return UniversityEligibilityPreflightResult::blocked('source_unavailable');
        }

        if (!array_is_list($snapshots) || count($snapshots) > 50) {
            return UniversityEligibilityPreflightResult::blocked('invalid_source');
        }

        $seen = [];
        $count = 0;
        foreach ($snapshots as $snapshot) {
            if (!$snapshot instanceof UniversityContractSnapshot) {
                return UniversityEligibilityPreflightResult::blocked('invalid_source');
            }
            if (!$snapshot->organizationId->equals($organization)) {
                // A single foreign tenant invalidates the entire response.
                return UniversityEligibilityPreflightResult::blocked('wrong_organization');
            }
            if (isset($seen[$snapshot->serviceReference])) {
                return UniversityEligibilityPreflightResult::blocked('duplicate_service');
            }
            $seen[$snapshot->serviceReference] = true;

            // A past record cannot be replayed to restore cancelled services.
            // Even one stale/future snapshot makes the whole batch unusable.
            if (
                $snapshot->observedAtEpoch > $nowEpoch
                || $snapshot->observedAtEpoch < $nowEpoch - 60
                || $snapshot->expiresAtEpoch <= $nowEpoch
                || $snapshot->expiresAtEpoch > $nowEpoch + 60
            ) {
                return UniversityEligibilityPreflightResult::blocked('stale_source');
            }

            if ($snapshot->isQualifyingCandidate()) {
                $count++;
            }
        }

        return UniversityEligibilityPreflightResult::inspected($count);
    }
}
