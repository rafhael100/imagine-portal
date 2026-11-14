<?php
declare(strict_types=1);
namespace Tests\Unit;

use App\Domain\Performance\ActivationPostpaidPreview as Candidate;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ActivationPostpaidPreviewTest extends TestCase
{
    private function eligibility(): array
    {
        return ['meta_ads'=>true,'google_ads'=>true,'chatgpt_ads'=>false];
    }

    public function test_activation_is_exactly_490_per_eligible_channel_with_no_media_charge(): void
    {
        $one=Candidate::activationQuote(['meta_ads'],$this->eligibility());
        self::assertSame(49_000,$one['activation_due_now_cents']);
        self::assertSame(70_000,$one['items'][0]['minimum_media_cents']);
        $two=Candidate::activationQuote(['google_ads','meta_ads'],$this->eligibility());
        self::assertSame(98_000,$two['activation_due_now_cents']);
        self::assertSame(['google_ads','meta_ads'],$two['channels']);
        self::assertTrue($two['media_paid_directly_to_platforms']);
        self::assertFalse($two['checkout_enabled']);
        self::assertFalse($two['asaas_write_enabled']);
        self::assertSame(17_000,$two['minimum_management_fee_cents']);
        self::assertSame('per_active_channel_full_month',$two['minimum_management_scope']);
    }

    public function test_three_eligible_channels_charge_1470_only_for_activation(): void
    {
        $eligible=$this->eligibility();$eligible['chatgpt_ads']=true;
        $quote=Candidate::activationQuote(['chatgpt_ads','meta_ads','google_ads'],$eligible);
        self::assertSame(147_000,$quote['activation_due_now_cents']);
        self::assertCount(3,$quote['items']);
        self::assertSame(1_200_00,$quote['items'][0]['minimum_media_cents']);
        self::assertSame(1_800_00,$quote['items'][0]['recommended_media_cents']);
    }

    public function test_unavailable_chatgpt_cannot_be_sold(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Candidate::activationQuote(['chatgpt_ads'],$this->eligibility());
    }

    public function test_meta_duplicate_never_counts_twice(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Candidate::activationQuote(['meta_ads','meta_ads'],$this->eligibility());
    }

    public function test_first_month_uses_activation_date_and_only_actual_media(): void
    {
        $result=Candidate::monthlyReview(
            ['meta_ads','google_ads'],'2026-11-14','2026-11',
            ['meta_ads'=>12_000,'google_ads'=>23_000],'SIM-EVIDENCE-01'
        );
        self::assertSame('2026-11-14',$result['start_date']);
        self::assertSame('2026-11-30',$result['end_date']);
        self::assertSame(35_000,$result['synthetic_actual_media_total_cents']);
        self::assertSame(7_000,$result['informational_management_20pct_cents']);
        self::assertTrue($result['finance_review_required']);
        self::assertFalse($result['real_invoice_authorized']);
        self::assertSame(17_000,$result['minimum_management_fee_cents']);
        self::assertSame(34_000,$result['informational_full_month_management_fee_cents']);
        self::assertTrue($result['first_month_partial_contract_review_required']);
    }

    public function test_subsequent_month_uses_civil_month_even_when_actual_is_zero(): void
    {
        $result=Candidate::monthlyReview(['meta_ads'],'2026-11-14','2026-12',['meta_ads'=>0],'SIM-CLOSE-DEC');
        self::assertSame('2026-12-01',$result['start_date']);
        self::assertSame('2026-12-31',$result['end_date']);
        self::assertSame(0,$result['informational_management_20pct_cents']);
        self::assertSame(17_000,$result['informational_full_month_management_fee_cents']);
        self::assertFalse($result['first_month_partial_contract_review_required']);
        self::assertFalse($result['real_invoice_authorized']);
    }

    public function test_period_before_activation_is_denied(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Candidate::monthlyReview(['meta_ads'],'2026-11-14','2026-10',['meta_ads'=>1],'SIM-CLOSE-OCT');
    }

    public function test_missing_channel_actual_is_denied(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Candidate::monthlyReview(['meta_ads','google_ads'],'2026-11-14','2026-11',['meta_ads'=>100],'SIM-BAD-ROW');
    }

    public function test_invalid_calendar_date_is_denied(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Candidate::monthlyReview(['meta_ads'],'2026-02-30','2026-03',['meta_ads'=>100],'SIM-BAD-DATE');
    }
    public function test_full_month_respects_minimum_per_channel_not_on_aggregated_spend(): void
    {
        // Explicitly approved examples: R$350 Meta => R$170 management;
        // R$4,000 Google => R$800. Sum is R$970, not max(20%, R$170).
        $result=Candidate::monthlyReview(
            ['meta_ads','google_ads'],'2026-10-01','2026-11',
            ['meta_ads'=>35_000,'google_ads'=>400_000],'SIM-FULL-350-4000'
        );
        self::assertSame(435_000,$result['synthetic_actual_media_total_cents']);
        self::assertSame(87_000,$result['informational_management_20pct_cents']);
        self::assertSame(97_000,$result['informational_full_month_management_fee_cents']);
        self::assertSame('google_ads',$result['channels'][0]['channel']);
        self::assertSame(80_000,$result['channels'][0]['informational_full_month_management_fee_cents']);
        self::assertFalse($result['channels'][0]['minimum_applied']);
        self::assertSame(17_000,$result['channels'][1]['informational_full_month_management_fee_cents']);
        self::assertTrue($result['channels'][1]['minimum_applied']);
        self::assertFalse($result['first_month_partial_contract_review_required']);
        self::assertFalse($result['real_invoice_authorized']);
    }

}
