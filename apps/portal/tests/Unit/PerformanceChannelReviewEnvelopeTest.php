<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Performance\ActivationGatewayReviewLab;
use App\Domain\Performance\PerformanceChannelReviewEnvelope as Envelope;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;

final class PerformanceChannelReviewEnvelopeTest extends TestCase
{
    private const KEY = 'synthetic-f415-channel-review-hmac-key-not-for-production';
    private const ISSUED = '2026-11-14T20:45:00Z';
    private const ORG = 'org-synthetic-001';
    private const ORDER = 'order-synthetic-001';
    private const ELIGIBLE = ['meta_ads' => true, 'google_ads' => true, 'chatgpt_ads' => false];

    private function review(array $channels = ['meta_ads', 'google_ads']): array
    {
        $policy = [
            'revision' => 7,
            'mode' => 'manual',
            'currency' => 'BRL',
            'preferred_gateway' => 'asaas',
            'providers' => [
                'asaas' => [
                    'enabled' => true, 'approved' => true, 'healthy' => true,
                    'fee_version' => 'SIM-AS-F415',
                    'methods' => ['pix' => [
                        'enabled' => true, 'fixed_cents' => 100,
                        'variable_bps' => 100, 'settlement_days' => 1,
                    ]],
                ],
                'c6' => [
                    'enabled' => false, 'approved' => false, 'healthy' => false,
                    'fee_version' => 'SIM-C6-F415', 'methods' => [],
                ],
            ],
        ];
        return ActivationGatewayReviewLab::preview(
            $channels, self::ELIGIBLE, $policy, 'pix',
            self::ORG, self::ORDER, 7, self::KEY
        );
    }

    private function issue(?array $review = null): array
    {
        return Envelope::issue(
            $review ?? $this->review(), self::ELIGIBLE,
            self::ORG, self::ORDER, 7, self::ISSUED, self::KEY
        );
    }

    private function verify(array $envelope, ?array $review = null, string $org = self::ORG, string $now = '2026-11-14T20:50:00Z'): bool
    {
        return Envelope::verify(
            $envelope, $review ?? $this->review(), self::ELIGIBLE, $org,
            self::ORDER, 7, $now, self::KEY
        );
    }

    public function test_review_binds_two_specific_channels_not_only_amount(): void
    {
        $wire = $this->issue();
        self::assertSame(['google_ads', 'meta_ads'], $wire['payload']['channels']);
        self::assertSame(98_000, $wire['payload']['activation_cents']);
        self::assertSame('asaas', $wire['payload']['gateway']);
        self::assertSame('pix', $wire['payload']['method']);
        self::assertSame('2026-11-14T21:00:00Z', $wire['payload']['expires_at']);
        self::assertSame(2000, $wire['payload']['management_fee_postpaid_basis_points']);
        self::assertSame(17_000,$wire['payload']['minimum_management_fee_cents']);
        self::assertSame('performance-postpaid-170-per-channel-2026-11-14',$wire['payload']['postpaid_policy_version']);
        self::assertFalse($wire['payload']['can_charge']);
        self::assertFalse($wire['payload']['can_activate']);
        self::assertFalse($wire['payload']['can_collect_personal_data']);
        self::assertTrue($this->verify($wire));
        self::assertTrue($this->verify($wire, now:'2026-11-14T21:00:00Z'));
    }

    public function test_other_channel_selection_same_price_is_not_substitutable(): void
    {
        $wire = $this->issue();
        $forged = $wire;
        $forged['payload']['channels'] = ['google_ads', 'chatgpt_ads'];
        self::assertFalse($this->verify($forged));
        // The full F413 source is also required to bind channel selection.
        self::assertFalse($this->verify($wire, $this->review(['meta_ads'])));
    }

    public function test_cross_tenant_expired_future_and_wrong_key_are_denied(): void
    {
        $wire = $this->issue();
        self::assertFalse($this->verify($wire, org:'org-another-001'));
        self::assertFalse($this->verify($wire, now:'2026-11-14T21:00:01Z'));
        self::assertFalse($this->verify($wire, now:'2026-11-14T20:44:59Z'));
        self::assertFalse(Envelope::verify(
            $wire, $this->review(), self::ELIGIBLE, self::ORG,
            self::ORDER, 7, '2026-11-14T20:50:00Z',
            'synthetic-different-f415-key-long-enough-to-check'
        ));
    }

    public function test_modified_amount_gateway_revision_permissions_or_expiry_are_denied(): void
    {
        foreach ([
            ['activation_cents'=>147_000],
            ['gateway'=>'c6'],
            ['policy_revision'=>8],
            ['can_charge'=>true],
            ['can_activate'=>true],
            ['can_collect_personal_data'=>true],
            ['expires_at'=>'2026-11-14T22:00:00Z'],
            ['organization_ref'=>'org-another-001'],
            ['crm_review_mac'=>str_repeat('a',64)],
            ['minimum_management_fee_cents'=>0],
            ['postpaid_policy_version'=>'outdated-policy'],
        ] as $delta) {
            $wire=$this->issue();
            $wire['payload']=array_replace($wire['payload'],$delta);
            self::assertFalse($this->verify($wire), 'Unexpected accepted change: '.json_encode($delta));
        }
    }

    public function test_unverified_crm_source_and_invalid_channel_eligibility_never_signed(): void
    {
        $review=$this->review();
        $review['crm_review']['mac']=str_repeat('0',64);
        $this->expectException(LogicException::class);
        $this->issue($review);
    }

    public function test_ineligible_chatgpt_cannot_gain_review_by_mutating_channels(): void
    {
        $review=$this->review();
        $review['channels']=['chatgpt_ads','meta_ads'];
        $this->expectException(InvalidArgumentException::class);
        $this->issue($review);
    }

    public function test_invalid_utc_calendar_timestamp_is_denied(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Envelope::issue($this->review(), self::ELIGIBLE, self::ORG, self::ORDER, 7, '2026-11-31T20:45:00Z', self::KEY);
    }

    public function test_extra_claims_do_not_survive_verification(): void
    {
        $wire=$this->issue();
        $wire['payload']['can_issue_invoice']=true;
        self::assertFalse($this->verify($wire));
    }
}
