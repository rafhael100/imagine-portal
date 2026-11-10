<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\ServicePortfolio\PortfolioKind;
use App\Domain\ServicePortfolio\PortfolioPreviewItem;
use App\Domain\ServicePortfolio\PortfolioProduct;
use App\Presentation\DemoContractOverview;
use App\Presentation\DemoServicePortfolio;
use App\Presentation\DemoServiceRequests;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class DemoServicePortfolioTest extends TestCase
{
    public function test_the_five_visible_products_are_typed_distinct_and_deterministic(): void
    {
        $items = DemoServicePortfolio::items();
        self::assertCount(5, $items);
        self::assertSame([
            PortfolioProduct::Onepage,
            PortfolioProduct::LandingPage,
            PortfolioProduct::PerformanceAI,
            PortfolioProduct::ImagineCRM,
            PortfolioProduct::UniversidadeImagine,
        ], array_map(static fn (PortfolioPreviewItem $item) => $item->product, $items));
        self::assertSame([
            'Sites Imagine · Onepage',
            'Sites Imagine · Landing Page',
            'Performance AI',
            'Imagine CRM',
            'Universidade Imagine',
        ], array_map(static fn (PortfolioPreviewItem $item) => $item->product->label(), $items));
        self::assertCount(5, array_unique(array_map(
            static fn (PortfolioPreviewItem $item) => $item->product->value, $items,
        )));
        self::assertEquals($items, DemoServicePortfolio::items());
    }

    public function test_commercial_boundaries_do_not_conflate_services_accounts_or_benefits(): void
    {
        foreach ([PortfolioProduct::Onepage, PortfolioProduct::LandingPage, PortfolioProduct::PerformanceAI] as $product) {
            self::assertSame(PortfolioKind::IndependentService, $product->kind());
            self::assertTrue($product->isStandaloneServiceByPolicy());
            self::assertFalse($product->requiresOwnLogin());
        }

        self::assertSame(PortfolioKind::IndependentAccount, PortfolioProduct::ImagineCRM->kind());
        self::assertTrue(PortfolioProduct::ImagineCRM->requiresOwnLogin());
        self::assertTrue(PortfolioProduct::ImagineCRM->isStandaloneServiceByPolicy());
        self::assertSame(PortfolioKind::IncludedBenefit, PortfolioProduct::UniversidadeImagine->kind());
        self::assertFalse(PortfolioProduct::UniversidadeImagine->isStandaloneServiceByPolicy());
        self::assertFalse(PortfolioProduct::UniversidadeImagine->requiresOwnLogin());
        self::assertSame('Benefício para clientes elegíveis', PortfolioKind::IncludedBenefit->label());
    }

    public function test_catalog_is_always_non_transactional_even_for_independent_services(): void
    {
        foreach (DemoServicePortfolio::items() as $item) {
            self::assertSame('Não contratável', $item->statusLabel());
            self::assertFalse($item->isCheckoutEnabled());
            self::assertFalse($item->grantsProductAccess());
            self::assertFalse($item->provesServiceEligibility());
            self::assertFalse($item->claimsActiveSubscription());
            self::assertTrue((new \ReflectionClass(PortfolioPreviewItem::class))->isReadOnly());
        }
        self::assertNull(PortfolioProduct::tryFrom('admin'));
        self::assertNull(PortfolioKind::tryFrom('subscription_active'));
    }

    public function test_all_three_service_followups_remain_canonical_and_no_new_contracts_are_invented(): void
    {
        $labels = array_map(
            static fn (PortfolioPreviewItem $item) => $item->product->label(),
            DemoServicePortfolio::items(),
        );
        foreach (DemoServiceRequests::services() as $service) {
            self::assertContains($service->product, $labels);
        }
        foreach (DemoContractOverview::records() as $contract) {
            self::assertContains($contract->serviceLabel, $labels);
        }
        self::assertCount(3, DemoContractOverview::records());
        self::assertCount(3, DemoServiceRequests::services());
        self::assertCount(5, DemoServicePortfolio::items());
    }

    public function test_descriptions_are_bounded_and_cannot_contain_control_characters(): void
    {
        foreach (['', '  ', str_repeat('X', 501), "Invalid\0content", "Invalid\n\x01content"] as $invalid) {
            try {
                new PortfolioPreviewItem(PortfolioProduct::Onepage, $invalid);
                self::fail('Invalid description accepted.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        $max = new PortfolioPreviewItem(PortfolioProduct::PerformanceAI, str_repeat('A', 500));
        self::assertSame(500, strlen($max->description));
    }

    public function test_service_and_account_separation_is_explicit_in_catalog_copy(): void
    {
        $items = DemoServicePortfolio::items();
        self::assertStringContainsString('independente da Landing Page', $items[0]->description);
        self::assertStringContainsString('separadamente da Onepage', $items[1]->description);
        self::assertStringContainsString('Google Ads e Meta', $items[2]->description);
        self::assertStringContainsString('login e sessão próprios', $items[3]->description);
        self::assertStringContainsString('sem SSO', $items[3]->description);
        self::assertStringContainsString('Não é assinatura avulsa', $items[4]->description);
        self::assertStringContainsString('nenhum acesso é concedido', $items[4]->description);
    }
}
