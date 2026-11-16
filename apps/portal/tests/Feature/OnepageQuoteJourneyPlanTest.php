<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Homologation\Commerce\OnepageQuoteJourneyPlan;
use App\Domain\PortalAccess\Authority\PortalBoundaryVerdict;
use InvalidArgumentException;
use Tests\TestCase;

/** Acceptance of an inert contract; no positive identity/grant fixture. */
final class OnepageQuoteJourneyPlanTest extends TestCase
{
    private const SELECTION = ['site' => 'onepage', 'channels' => [], 'tier' => 'minimum'];

    public function test_onepage_selection_recomputes_and_resumes_the_same_in_memory_plan(): void
    {
        $plan = OnepageQuoteJourneyPlan::prepare(self::SELECTION, 'synthetic-company-a', 'request-001');
        $first = $plan->resume(self::SELECTION, 'synthetic-company-a', 'request-001');
        $again = $plan->resume(self::SELECTION, 'synthetic-company-a', 'request-001');
        self::assertSame($first, $again);
        // Single selection from Comercial #442 78c74b646cc9cf494464ba5aa85f198be1f0efef.
        self::assertSame(99000, $first['quote']['setup_activation_total_cents']);
        self::assertSame([33000, 33000, 33000], $first['quote']['illustrative_three_parts_cents']);
        self::assertSame(7990, $first['quote']['site_monthly_cents']);
        self::assertSame(90, $first['quote']['site_monthly_first_due_offset_days']);
        self::assertSame(0, $first['quote']['advertising_activation_cents']);
        self::assertSame(0, $first['quote']['media_paid_directly_to_platforms_cents']);
        self::assertSame(0, $first['quote']['management_estimate_postpaid_cents']);
        self::assertTrue($first['quote']['catalog_server_recomputed']);
        self::assertSame('PROPOSED_UNVERIFIED', $first['binding_status']);
        foreach (['authority_verified', 'draft_persisted', 'persistence_allowed',
            'order_created', 'payment_requested', 'site_provisioned'] as $flag) {
            self::assertFalse($first[$flag]);
        }
        self::assertFalse($first['quote']['public_checkout_authorized']);
        self::assertFalse($first['quote']['real_charge_enabled']);
        self::assertSame('HOLD_NATIVE_QUOTE_AUTHORITY_AND_MINIMUM_PRIVILEGE_STORE', $first['release_status']);
    }

    public function test_reordered_selection_and_repeated_request_have_one_proposed_scope(): void
    {
        $reordered = ['tier' => 'minimum', 'channels' => [], 'site' => 'onepage'];
        $a = OnepageQuoteJourneyPlan::prepare(self::SELECTION, 'company-a', 'request-001');
        $b = OnepageQuoteJourneyPlan::prepare($reordered, 'company-a', 'request-001');
        self::assertSame($a->resume($reordered, 'company-a', 'request-001'),
            $b->resume(self::SELECTION, 'company-a', 'request-001'));
        // Not a database insert, lock, concurrent replay or durable deduplication proof.
    }

    public function test_same_request_key_in_two_companies_has_separate_proposed_scopes(): void
    {
        $a = OnepageQuoteJourneyPlan::prepare(self::SELECTION, 'company-a', 'request-001');
        $b = OnepageQuoteJourneyPlan::prepare(self::SELECTION, 'company-b', 'request-001');
        self::assertNotSame($a->resume(self::SELECTION, 'company-a', 'request-001')['proposed_idempotency_scope'],
            $b->resume(self::SELECTION, 'company-b', 'request-001')['proposed_idempotency_scope']);
        $this->expectExceptionMessage('ONEPAGE_PLAN_CONTEXT_MISMATCH');
        $a->resume(self::SELECTION, 'company-b', 'request-001');
    }

    public function test_resume_refuses_a_different_request_key(): void
    {
        $plan = OnepageQuoteJourneyPlan::prepare(self::SELECTION, 'company-a', 'request-001');
        $this->expectExceptionMessage('ONEPAGE_PLAN_CONTEXT_MISMATCH');
        $plan->resume(self::SELECTION, 'company-a', 'request-002');
    }

    public function test_same_key_cannot_silently_replace_selection_even_when_onepage_price_is_equal(): void
    {
        $plan = OnepageQuoteJourneyPlan::prepare(self::SELECTION, 'company-a', 'request-001');
        $this->expectExceptionMessage('ONEPAGE_PLAN_CONTEXT_MISMATCH');
        $plan->resume(array_replace(self::SELECTION, ['tier' => 'recommended']), 'company-a', 'request-001');
    }

    public function test_next_stage_ads_selection_is_held_outside_this_contract(): void
    {
        $this->expectException(InvalidArgumentException::class);
        OnepageQuoteJourneyPlan::prepare(array_replace(self::SELECTION, ['channels' => ['google_ads']]),
            'company-a', 'request-001');
    }

    public function test_missing_context_cannot_create_a_resumable_plan(): void
    {
        $this->expectExceptionMessage('ONEPAGE_PLAN_CONTEXT_REQUIRED');
        OnepageQuoteJourneyPlan::prepare(self::SELECTION, '', 'request-001');
    }

    public function test_contract_does_not_bind_authority_open_routes_or_promote_a_synthetic_verdict(): void
    {
        self::assertFalse($this->app->bound(OnepageQuoteJourneyPlan::class));
        self::assertSame(['up'], array_map(fn ($route) => $route->uri(),
            iterator_to_array($this->app['router']->getRoutes())));
        self::assertFalse(PortalBoundaryVerdict::syntheticCandidate()->canAuthorizeHttp());
        $plan = OnepageQuoteJourneyPlan::prepare(self::SELECTION, 'company-a', 'request-001');
        self::assertFalse($plan->resume(self::SELECTION, 'company-a', 'request-001')['persistence_allowed']);
    }
}
