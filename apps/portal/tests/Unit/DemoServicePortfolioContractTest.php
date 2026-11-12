<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\ServicePortfolio\PortfolioKind;
use App\Domain\ServicePortfolio\PortfolioPreviewItem;
use App\Domain\ServicePortfolio\PortfolioProduct;
use App\Presentation\DemoServicePortfolio;
use App\Presentation\DemoServiceRequests;
use App\Presentation\DemoUniversityBenefitRequirements;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class DemoServicePortfolioContractTest extends TestCase
{
    public function test_all_five_products_are_distinct_and_not_sold_by_preview(): void
    {
        $items = DemoServicePortfolio::items();
        self::assertCount(5, $items);
        self::assertSame([
            PortfolioProduct::Onepage, PortfolioProduct::LandingPage,
            PortfolioProduct::PerformanceAI, PortfolioProduct::ImagineCRM,
            PortfolioProduct::UniversidadeImagine,
        ], array_map(static fn (PortfolioPreviewItem $i) => $i->product, $items));
        self::assertCount(5, array_unique(array_map(
            static fn (PortfolioPreviewItem $i) => $i->product->value, $items,
        )));
        self::assertEquals($items, DemoServicePortfolio::items());
        foreach ($items as $item) {
            self::assertSame('Não contratável', $item->statusLabel());
            self::assertFalse($item->isCheckoutEnabled());
            self::assertFalse($item->grantsProductAccess());
            self::assertFalse($item->provesServiceEligibility());
            self::assertFalse($item->claimsActiveSubscription());
        }
    }

    public function test_services_crm_and_university_have_separate_contract_boundaries(): void
    {
        foreach ([PortfolioProduct::Onepage, PortfolioProduct::LandingPage,
                  PortfolioProduct::PerformanceAI] as $product) {
            self::assertSame(PortfolioKind::IndependentService, $product->kind());
            self::assertFalse($product->requiresOwnLogin());
        }
        self::assertSame(PortfolioKind::IndependentAccount, PortfolioProduct::ImagineCRM->kind());
        self::assertTrue(PortfolioProduct::ImagineCRM->requiresOwnLogin());
        self::assertSame(PortfolioKind::IncludedBenefit, PortfolioProduct::UniversidadeImagine->kind());
        self::assertFalse(PortfolioProduct::UniversidadeImagine->isStandaloneServiceByPolicy());
        self::assertCount(4, DemoUniversityBenefitRequirements::items());

        $catalogLabels = array_map(
            static fn (PortfolioPreviewItem $i) => $i->product->label(),
            DemoServicePortfolio::items(),
        );
        foreach (DemoServiceRequests::services() as $service) {
            self::assertContains($service->product, $catalogLabels);
        }
        self::assertSame(3, count(DemoServiceRequests::services()));
    }

    public function test_untrusted_descriptions_are_not_permitted_in_the_static_type(): void
    {
        foreach (['', "  ", str_repeat('X', 501), "Invalid\0entry", "Bad\n\x01value"] as $invalid) {
            try {
                new PortfolioPreviewItem(PortfolioProduct::Onepage, $invalid);
                self::fail('Accepted invalid description');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertTrue((new \ReflectionClass(PortfolioPreviewItem::class))->isReadOnly());
    }
}
