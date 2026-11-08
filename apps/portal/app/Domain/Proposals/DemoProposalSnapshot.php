<?php

namespace App\Domain\Proposals;

use App\Domain\Catalog\OfferingId;
use App\Domain\Catalog\PerformanceChannel;
use App\Domain\Catalog\PerformanceModality;
use App\Domain\Organizations\OrganizationId;
use JsonException;

/**
 * In-memory, immutable, nonbinding proposal snapshot. No commercial amounts
 * can be set by construction: null means NOT HOMOLOGATED, never free of charge.
 */
final readonly class DemoProposalSnapshot
{
    public ?int $monthlyPriceCents;
    public ?int $taxCents;
    public ?int $totalCents;
    public ?string $approvedContractVersion;

    /**
     * @param list<PerformanceChannel> $channels
     * @param list<DemoAddonSelection> $requestedAddons
     */
    public function __construct(
        public DemoProposalId $id,
        public OrganizationId $organizationId,
        public OfferingId $offeringId,
        public string $offeringVersion,
        public PerformanceModality $modality,
        public array $channels,
        public array $requestedAddons,
        public string $demoTermsVersion,
        public DemoProposalStatus $status = DemoProposalStatus::PresentedDemo,
    ) {
        // Deliberately fixed: no caller input can assert an official price/contract.
        $this->monthlyPriceCents = null;
        $this->taxCents = null;
        $this->totalCents = null;
        $this->approvedContractVersion = null;
    }

    /**
     * Deterministic evidence of what was presented, without binding agreement.
     *
     * @throws JsonException
     */
    public function fingerprint(): string
    {
        return hash('sha256', json_encode([
            'kind' => 'imagine.performance.demo-proposal.v1',
            'proposal_id' => $this->id->value,
            'portal_organization_id' => $this->organizationId->value,
            'offering_id' => $this->offeringId->value,
            'offering_version' => $this->offeringVersion,
            'modality' => $this->modality->value,
            'channels' => array_map(static fn (PerformanceChannel $c): string => $c->value, $this->channels),
            'demo_addons' => array_map(
                static fn (DemoAddonSelection $a): array => [$a->code, $a->quantity],
                $this->requestedAddons,
            ),
            'demo_terms_version' => $this->demoTermsVersion,
            'financials' => null,
            'approved_contract_version' => null,
            'status' => $this->status->value,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    public function isCheckoutEligible(): bool
    {
        return false;
    }

    public function isLegallyBinding(): bool
    {
        return false;
    }
}
