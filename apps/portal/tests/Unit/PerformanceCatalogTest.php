<?php

namespace Tests\Unit;

use App\Domain\Catalog\OfferingEligibility;
use App\Domain\Catalog\OfferingId;
use App\Domain\Catalog\OfferingStatus;
use App\Domain\Catalog\PerformanceChannel;
use App\Domain\Catalog\PerformanceModality;
use App\Domain\Catalog\PerformanceOffering;
use App\Domain\Catalog\ProductCode;
use App\Domain\Organizations\OrganizationId;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PerformanceCatalogTest extends TestCase
{
    /** @param list<PerformanceChannel> $channels */
    private function offer(
        PerformanceModality $modality = PerformanceModality::SingleChannel,
        array $channels = [PerformanceChannel::GoogleAds],
        OfferingStatus $status = OfferingStatus::NotForSale,
        ?int $campaignLimit = null,
        ?int $adGroupLimit = null,
    ): PerformanceOffering {
        return new PerformanceOffering(
            new OfferingId('performance-offer-001'),
            ProductCode::PerformanceAI,
            'v0.1',
            'Oferta de demonstração, não contratável',
            $status,
            $modality,
            $channels,
            $campaignLimit,
            $adGroupLimit,
        );
    }

    public function test_single_channel_supports_google_or_meta_only(): void
    {
        foreach ([PerformanceChannel::GoogleAds, PerformanceChannel::MetaAds] as $channel) {
            $offer = $this->offer(channels: [$channel]);
            self::assertSame([$channel], $offer->channels);
            self::assertSame(ProductCode::PerformanceAI, $offer->product);
        }
    }

    public function test_multichannel_requires_google_and_meta(): void
    {
        $offer = $this->offer(PerformanceModality::Multichannel, [
            PerformanceChannel::MetaAds, PerformanceChannel::GoogleAds,
        ]);
        self::assertCount(2, $offer->channels);
    }

    public function test_single_channel_rejects_two_channels(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->offer(channels: [PerformanceChannel::GoogleAds, PerformanceChannel::MetaAds]);
    }

    public function test_multichannel_rejects_only_one_channel(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->offer(PerformanceModality::Multichannel, [PerformanceChannel::GoogleAds]);
    }

    public function test_empty_and_duplicate_channels_are_rejected(): void
    {
        foreach ([[], [PerformanceChannel::MetaAds, PerformanceChannel::MetaAds]] as $channels) {
            try {
                $this->offer(channels: $channels);
                self::fail('An invalid channel list was accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_invalid_channel_enum_and_sale_status_are_unrepresentable(): void
    {
        self::assertNull(PerformanceChannel::tryFrom('tiktok'));
        self::assertNull(OfferingStatus::tryFrom('for_sale'));
        self::assertNull(OfferingStatus::tryFrom('active'));
        self::assertNull(ProductCode::tryFrom('imagine_crm'));
    }

    public function test_expanded_operation_does_not_create_a_third_channel(): void
    {
        $offer = $this->offer(PerformanceModality::ExpandedOperation, [
            PerformanceChannel::GoogleAds, PerformanceChannel::MetaAds,
        ]);
        self::assertSame(PerformanceModality::ExpandedOperation, $offer->modality);
        self::assertCount(2, $offer->channels);
    }

    public function test_capacity_null_is_unknown_and_does_not_enable_checkout(): void
    {
        $offer = $this->offer(status: OfferingStatus::ReadyForReview);
        self::assertNull($offer->campaignLimit);
        self::assertNull($offer->adGroupLimit);
        $gate = new OfferingEligibility();
        self::assertContains('capacity_not_homologated', $gate->blockers($offer, new OrganizationId('org-a')));
        self::assertFalse($gate->canCheckout($offer, new OrganizationId('org-a')));
    }

    public function test_even_known_illustrative_limits_cannot_make_an_offer_saleable(): void
    {
        $offer = $this->offer(
            status: OfferingStatus::ReadyForReview,
            campaignLimit: 2,
            adGroupLimit: 4,
        );
        $gate = new OfferingEligibility();
        self::assertFalse($gate->canCheckout($offer, new OrganizationId('org-a')));
        self::assertContains('price_not_homologated', $gate->blockers($offer, new OrganizationId('org-a')));
        self::assertContains('contract_not_homologated', $gate->blockers($offer, new OrganizationId('org-a')));
    }

    public function test_missing_organization_and_unapproved_state_block_sale(): void
    {
        $gate = new OfferingEligibility();
        $reasons = $gate->blockers($this->offer(), null);
        self::assertContains('organization_context_missing', $reasons);
        self::assertContains('offering_not_ready_for_review', $reasons);
    }

    public function test_known_zero_or_negative_limits_are_invalid(): void
    {
        foreach ([0, -1] as $limit) {
            try {
                $this->offer(campaignLimit: $limit);
                self::fail('A nonpositive campaign limit was accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }

            try {
                $this->offer(adGroupLimit: $limit);
                self::fail('A nonpositive group limit was accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_identifiers_version_and_description_are_validated(): void
    {
        foreach (['', '../other', 'with space'] as $value) {
            try {
                new OfferingId($value);
                self::fail('An invalid ID was accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }

        $this->expectException(InvalidArgumentException::class);
        new PerformanceOffering(
            new OfferingId('offer-a'),
            ProductCode::PerformanceAI,
            'unversioned',
            'Descrição',
            OfferingStatus::Draft,
            PerformanceModality::SingleChannel,
            [PerformanceChannel::GoogleAds],
        );
    }

    public function test_offering_definition_is_readonly_and_versioned(): void
    {
        $offer = $this->offer();
        self::assertSame('v0.1', $offer->version);
        self::assertTrue((new \ReflectionClass(PerformanceOffering::class))->isReadOnly());
        self::assertTrue($offer->id->equals(new OfferingId('performance-offer-001')));
        self::assertFalse($offer->id->equals(new OfferingId('different')));
    }
}
