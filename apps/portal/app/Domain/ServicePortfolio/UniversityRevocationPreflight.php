<?php

declare(strict_types=1);

namespace App\Domain\ServicePortfolio;

use App\Domain\Organizations\OrganizationId;
use Throwable;

/**
 * Read-only two-source reconciliation candidate. NO actual issuer, HTTP
 * endpoint, SSO, grants or live contracts are installed.
 *
 * In a future trusted flow, revision data must come from an independently
 * authenticated source and match every service snapshot. This pure check
 * never turns a caller-supplied object into an operational entitlement.
 */
final readonly class UniversityRevocationPreflight
{
    public function __construct(
        private UniversityContractAuthority $contracts = new UnavailableUniversityContractAuthority(),
        private UniversityServiceRevisionAuthority $revisions = new UnavailableUniversityServiceRevisionAuthority(),
    ) {
    }

    public function inspect(
        ?OrganizationId $organization,
        int $now,
    ): UniversityRevocationPreflightResult {
        if ($organization === null || $now <= 0) {
            return UniversityRevocationPreflightResult::blocked('invalid_context');
        }

        try {
            $records = $this->contracts->currentFor($organization);
            $revision = $this->revisions->currentFor($organization);
        } catch (Throwable) {
            return UniversityRevocationPreflightResult::blocked('source_unavailable');
        }

        if ($records === null || $revision === null) {
            return UniversityRevocationPreflightResult::blocked('source_unavailable');
        }

        if (!is_array($records) || !array_is_list($records) || count($records) > 50) {
            return UniversityRevocationPreflightResult::blocked('invalid_contract_source');
        }

        if (!$revision->organizationId->equals($organization)) {
            return UniversityRevocationPreflightResult::blocked('wrong_revision_organization');
        }

        if ($revision->observedAtEpoch > $now
            || $revision->observedAtEpoch < $now - 60
            || $revision->expiresAtEpoch <= $now
            || $revision->expiresAtEpoch > $now + 60) {
            return UniversityRevocationPreflightResult::blocked('stale_revision_source');
        }

        // Freeze the previously read result: never re-query a mutable source
        // between the contract and revocation comparisons (TOCTOU).
        $frozen = new class($records) implements UniversityContractAuthority {
            public function __construct(private array $records)
            {
            }

            public function currentFor(OrganizationId $organization): ?array
            {
                return $this->records;
            }
        };
        $base = (new UniversityEligibilityPreflight($frozen))
            ->inspect($organization, $now);

        if (!in_array($base->reason, ['contract_candidate', 'no_eligible_service'], true)) {
            return UniversityRevocationPreflightResult::blocked('contract_'.$base->reason);
        }

        // A revocation feed must have exactly the set of current services.
        // Missing or extra entries are not silently ignored.
        if (count($revision->revisions) !== count($records)) {
            return UniversityRevocationPreflightResult::blocked('revision_set_mismatch');
        }

        foreach ($records as $record) {
            if (!$record instanceof UniversityContractSnapshot
                || !array_key_exists($record->serviceReference, $revision->revisions)) {
                return UniversityRevocationPreflightResult::blocked('revision_set_mismatch');
            }

            if ($revision->revisions[$record->serviceReference] !== $record->sourceRevision) {
                return UniversityRevocationPreflightResult::blocked('contract_revision_mismatch');
            }

            if ($record->isQualifyingCandidate()
                && in_array($record->serviceReference, $revision->revokedReferences, true)) {
                return UniversityRevocationPreflightResult::blocked('revoked_active_service');
            }
        }

        return UniversityRevocationPreflightResult::inspected($base->candidateCount);
    }
}
