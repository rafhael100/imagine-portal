<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\PaymentRouting\PaymentIntentLifecyclePreview;
use LogicException;
use PHPUnit\Framework\TestCase;

final class PaymentIntentLifecyclePreviewTest extends TestCase
{
    private function pending(): array
    {
        $intent = PaymentIntentLifecyclePreview::open('org-001', 'intent-20261114-a', 'asaas', 'pix', 10_000);
        $intent = PaymentIntentLifecyclePreview::advance($intent, 'dispatch_started', 'asaas');
        return PaymentIntentLifecyclePreview::advance($intent, 'created', 'asaas', 'pay_123');
    }

    public function test_verified_payment_lifecycle_and_idempotent_replays(): void
    {
        $intent = $this->pending();
        self::assertSame('pending', $intent['status']);
        self::assertSame($intent, PaymentIntentLifecyclePreview::advance($intent, 'created', 'asaas', 'pay_123'));
        $paid = PaymentIntentLifecyclePreview::advance($intent, 'verified_paid', 'asaas', 'pay_123');
        self::assertSame('settled', $paid['status']);
        self::assertSame($paid, PaymentIntentLifecyclePreview::advance($paid, 'verified_paid', 'asaas', 'pay_123'));
    }

    public function test_uncertain_write_cannot_be_routed_away_or_retried_blindly(): void
    {
        $intent = PaymentIntentLifecyclePreview::open('org-001', 'intent-20261114-b', 'asaas', 'boleto', 2_500);
        $intent = PaymentIntentLifecyclePreview::advance($intent, 'dispatch_started', 'asaas');
        $intent = PaymentIntentLifecyclePreview::advance($intent, 'write_uncertain', 'asaas');
        self::assertSame('uncertain', $intent['status']);
        $this->expectException(LogicException::class);
        PaymentIntentLifecyclePreview::advance($intent, 'dispatch_started', 'asaas');
    }

    public function test_verified_absence_does_not_migrate_original_intent(): void
    {
        $intent = PaymentIntentLifecyclePreview::open('org-001', 'intent-20261114-c', 'asaas', 'pix', 2_500);
        $intent = PaymentIntentLifecyclePreview::advance($intent, 'dispatch_started', 'asaas');
        $intent = PaymentIntentLifecyclePreview::advance($intent, 'write_uncertain', 'asaas');
        $intent = PaymentIntentLifecyclePreview::advance($intent, 'verified_absent', 'asaas');
        self::assertSame('absent', $intent['status']);
        $this->expectException(LogicException::class);
        PaymentIntentLifecyclePreview::routeExisting($intent, 'c6');
    }

    public function test_cross_provider_events_fail(): void
    {
        $this->expectException(LogicException::class);
        PaymentIntentLifecyclePreview::advance($this->pending(), 'verified_paid', 'c6', 'pay_123');
    }

    public function test_reference_mismatch_cannot_settle(): void
    {
        $this->expectException(LogicException::class);
        PaymentIntentLifecyclePreview::advance($this->pending(), 'verified_paid', 'asaas', 'pay_OTHER');
    }

    public function test_finalized_intent_cannot_start_dispatch_again(): void
    {
        $intent = PaymentIntentLifecyclePreview::advance($this->pending(), 'verified_paid', 'asaas', 'pay_123');
        $this->expectException(LogicException::class);
        PaymentIntentLifecyclePreview::advance($intent, 'dispatch_started', 'asaas');
    }
}
