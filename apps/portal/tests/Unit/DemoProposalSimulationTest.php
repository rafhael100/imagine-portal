<?php

namespace Tests\Unit;

use App\Domain\Catalog\OfferingId;
use App\Domain\Catalog\OfferingStatus;
use App\Domain\Catalog\PerformanceChannel;
use App\Domain\Catalog\PerformanceModality;
use App\Domain\Catalog\PerformanceOffering;
use App\Domain\Catalog\ProductCode;
use App\Domain\Organizations\OrganizationId;
use App\Domain\Proposals\DemoAddonSelection;
use App\Domain\Proposals\DemoProposalId;
use App\Domain\Proposals\DemoProposalSimulator;
use App\Domain\Proposals\DemoProposalStatus;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class DemoProposalSimulationTest extends TestCase
{
    private function offering(
        PerformanceModality $modality = PerformanceModality::SingleChannel,
        array $channels = [PerformanceChannel::GoogleAds],
        OfferingStatus $status = OfferingStatus::NotForSale,
    ): PerformanceOffering {
        return new PerformanceOffering(
            new OfferingId('offer-demo-a'),
            ProductCode::PerformanceAI,
            'v0.1',
            'Oferta puramente demonstrativa',
            $status,
            $modality,
            $channels,
        );
    }

    public function test_snapshot_contains_versions_channels_and_explicit_noncommercial_status(): void
    {
        $proposal = (new DemoProposalSimulator())->present(
            new DemoProposalId('prop-a'),
            new OrganizationId('org-a'),
            $this->offering(),
            'demo-v0.1',
        );

        self::assertSame('offer-demo-a', $proposal->offeringId->value);
        self::assertSame('v0.1', $proposal->offeringVersion);
        self::assertSame('demo-v0.1', $proposal->demoTermsVersion);
        self::assertSame(DemoProposalStatus::PresentedDemo, $proposal->status);
        self::assertSame([PerformanceChannel::GoogleAds], $proposal->channels);
        self::assertFalse($proposal->isCheckoutEligible());
        self::assertFalse($proposal->isLegallyBinding());
    }

    public function test_financial_values_and_real_contract_version_are_never_inferred(): void
    {
        $proposal = (new DemoProposalSimulator())->present(
            new DemoProposalId('prop-a'), new OrganizationId('org-a'), $this->offering(), 'demo-v0.1',
        );

        self::assertNull($proposal->monthlyPriceCents);
        self::assertNull($proposal->taxCents);
        self::assertNull($proposal->totalCents);
        self::assertNull($proposal->approvedContractVersion);
        self::assertStringNotContainsString('0.00', $proposal->fingerprint());
    }

    public function test_multichannel_channels_and_addons_are_deterministic_across_input_order(): void
    {
        $sim = new DemoProposalSimulator();
        $offeringA = $this->offering(PerformanceModality::Multichannel, [
            PerformanceChannel::MetaAds, PerformanceChannel::GoogleAds,
        ]);
        $offeringB = $this->offering(PerformanceModality::Multichannel, [
            PerformanceChannel::GoogleAds, PerformanceChannel::MetaAds,
        ]);
        $addons = [new DemoAddonSelection('demo_extra_campaign', 2), new DemoAddonSelection('demo_creative_request', 1)];
        $a = $sim->present(new DemoProposalId('prop-a'), new OrganizationId('org-a'), $offeringA, 'demo-v0.1', $addons);
        $b = $sim->present(new DemoProposalId('prop-a'), new OrganizationId('org-a'), $offeringB, 'demo-v0.1', array_reverse($addons));

        self::assertSame($a->fingerprint(), $b->fingerprint());
        self::assertSame([PerformanceChannel::GoogleAds, PerformanceChannel::MetaAds], $a->channels);
        self::assertSame('demo_creative_request', $a->requestedAddons[0]->code);
    }

    public function test_different_organization_offering_version_or_addon_changes_fingerprint(): void
    {
        $sim = new DemoProposalSimulator();
        $base = $sim->present(new DemoProposalId('prop-a'), new OrganizationId('org-a'), $this->offering(), 'demo-v0.1');
        $foreign = $sim->present(new DemoProposalId('prop-a'), new OrganizationId('org-b'), $this->offering(), 'demo-v0.1');
        $addons = $sim->present(new DemoProposalId('prop-a'), new OrganizationId('org-a'), $this->offering(), 'demo-v0.1', [new DemoAddonSelection('demo_extra_campaign', 1)]);
        $terms = $sim->present(new DemoProposalId('prop-a'), new OrganizationId('org-a'), $this->offering(), 'demo-v0.2');

        self::assertNotSame($base->fingerprint(), $foreign->fingerprint());
        self::assertNotSame($base->fingerprint(), $addons->fingerprint());
        self::assertNotSame($base->fingerprint(), $terms->fingerprint());
    }

    public function test_draft_offering_cannot_be_presented(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new DemoProposalSimulator())->present(new DemoProposalId('prop-a'), new OrganizationId('org-a'), $this->offering(status: OfferingStatus::Draft), 'demo-v0.1');
    }

    public function test_unversioned_or_non_demo_terms_are_rejected(): void
    {
        foreach (['', 'v0.1', 'contract-v1', 'demo-vx.y'] as $value) {
            try {
                (new DemoProposalSimulator())->present(new DemoProposalId('prop-a'), new OrganizationId('org-a'), $this->offering(), $value);
                self::fail('An invalid demo terms version was accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_addon_codes_require_demo_prefix_and_quantity_is_bounded(): void
    {
        foreach ([['extra_campaign', 1], ['demo_extra', 0], ['demo_extra', 21]] as [$code, $quantity]) {
            try {
                new DemoAddonSelection($code, $quantity);
                self::fail('Non-demonstrative add-on accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_duplicate_addons_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new DemoProposalSimulator())->present(
            new DemoProposalId('prop-a'), new OrganizationId('org-a'), $this->offering(), 'demo-v0.1',
            [new DemoAddonSelection('demo_extra', 1), new DemoAddonSelection('demo_extra', 3)],
        );
    }

    public function test_untyped_addon_entries_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new DemoProposalSimulator())->present(
            new DemoProposalId('prop-a'), new OrganizationId('org-a'), $this->offering(), 'demo-v0.1',
            ['demo_extra'],
        );
    }

    public function test_large_demo_addon_selection_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $addons = [];
        for ($i = 1; $i <= 9; $i++) {
            $addons[] = new DemoAddonSelection('demo_extra_'.$i, 1);
        }
        (new DemoProposalSimulator())->present(
            new DemoProposalId('prop-a'), new OrganizationId('org-a'), $this->offering(), 'demo-v0.1', $addons,
        );
    }

    public function test_proposal_id_validation_and_snapshot_immutability(): void
    {
        foreach (['', '../escape', 'with space'] as $value) {
            try {
                new DemoProposalId($value);
                self::fail('Invalid proposal ID accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }

        $snapshot = (new DemoProposalSimulator())->present(
            new DemoProposalId('prop-a'), new OrganizationId('org-a'), $this->offering(), 'demo-v0.1',
        );
        self::assertTrue((new \ReflectionClass($snapshot))->isReadOnly());
    }
}
