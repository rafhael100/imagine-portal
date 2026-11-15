<?php

namespace App\Domain\Catalog;

use App\Domain\Organizations\OrganizationId;

/**
 * P02-A is a safe non-transactional catalog: no sale is ever eligible.
 *
 * No caller-supplied boolean, version or tenant ID may impersonate pricing,
 * terms, authorization or a future approval issued by the Portal backend.
 */
final readonly class OfferingEligibility
{
    /** @return list<string> Explicit blockers for a future, audited checkout gate. */
    public function blockers(PerformanceOffering $offering, ?OrganizationId $organization): array
    {
        $blockers = [
            'p02_a_checkout_not_implemented',
            'price_not_homologated',
            'contract_not_homologated',
            'commercial_authorization_not_homologated',
        ];

        if ($organization === null) {
            $blockers[] = 'organization_context_missing';
        }

        if ($offering->status !== OfferingStatus::ReadyForReview) {
            $blockers[] = 'offering_not_ready_for_review';
        }

        if ($offering->campaignLimit === null || $offering->adGroupLimit === null) {
            $blockers[] = 'capacity_not_homologated';
        }

        return $blockers;
    }

    public function canCheckout(PerformanceOffering $offering, ?OrganizationId $organization): bool
    {
        return $this->blockers($offering, $organization) === [];
    }
}
