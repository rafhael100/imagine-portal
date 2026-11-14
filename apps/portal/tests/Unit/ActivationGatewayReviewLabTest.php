<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Performance\ActivationGatewayReviewLab;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ActivationGatewayReviewLabTest extends TestCase
{
    private const KEY='synthetic-portal-crm-review-key-only-for-offline-f413';

    private function policy(string $mode='manual'): array
    {
        return [
            'revision'=>2, 'mode'=>$mode, 'currency'=>'BRL',
            'preferred_gateway'=>'asaas',
            'providers'=>[
                'asaas'=>[
                    'enabled'=>true,'approved'=>true,'healthy'=>true,
                    'fee_version'=>'SIM-ASAAS-F413',
                    'methods'=>[
                        'pix'=>['enabled'=>true,'fixed_cents'=>100,'variable_bps'=>100,'settlement_days'=>1],
                        'boleto'=>['enabled'=>true,'fixed_cents'=>230,'variable_bps'=>0,'settlement_days'=>2],
                    ],
                ],
                'c6'=>[
                    'enabled'=>false,'approved'=>false,'healthy'=>false,
                    'fee_version'=>'SIM-C6-F413',
                    'methods'=>[
                        'pix'=>['enabled'=>true,'fixed_cents'=>30,'variable_bps'=>50,'settlement_days'=>1],
                    ],
                ],
            ],
        ];
    }

    private function preview(
        array $channels=['meta_ads'],array $eligibility=['meta_ads'=>true],
        ?array $policy=null,int $revision=2,string $method='pix'
    ): array {
        return ActivationGatewayReviewLab::preview(
            $channels,$eligibility,$policy??$this->policy(),$method,
            'org-synthetic-001','order-f413-001',$revision,self::KEY
        );
    }

    public function test_one_meta_activation_is_490_not_media_or_advance(): void
    {
        $v=$this->preview();
        self::assertSame(49_000,$v['activation_cents']);
        self::assertSame(['meta_ads'],$v['channels']);
        self::assertSame('asaas',$v['gateway_quote']['gateway']);
        self::assertSame('BRL',$v['currency']);
        self::assertSame(49_000,$v['gateway_quote']['gross_cents']);
        self::assertSame(590,$v['gateway_quote']['estimated_fee_cents']);
        self::assertSame(48_410,$v['gateway_quote']['estimated_net_cents']);
        self::assertSame(49_000,$v['crm_review']['payload']['amount_cents']);
        self::assertSame(2,$v['crm_review']['payload']['policy_revision']);
        self::assertFalse($v['crm_review']['payload']['can_charge']);
        self::assertFalse($v['can_charge']);
        self::assertFalse($v['can_activate']);
        self::assertTrue($v['media_paid_to_platforms_not_this_gateway']);
        self::assertNull($v['minimum_management_fee_cents']);
    }

    public function test_two_channels_activation_not_the_planned_media_amount(): void
    {
        $v=$this->preview(['meta_ads','google_ads'],
            ['meta_ads'=>true,'google_ads'=>true]);
        self::assertSame(98_000,$v['activation_cents']);
        self::assertSame(98_000,$v['gateway_quote']['gross_cents']);
        self::assertSame(98_000,$v['crm_review']['payload']['amount_cents']);
        self::assertSame(2_000,$v['management_fee_postpaid_basis_points']);
    }

    public function test_three_channels_require_explicit_eligibility_even_in_lab(): void
    {
        $v=$this->preview(
            ['google_ads','meta_ads','chatgpt_ads'],
            ['meta_ads'=>true,'google_ads'=>true,'chatgpt_ads'=>true]
        );
        self::assertSame(147_000,$v['activation_cents']);
        self::assertSame(147_000,$v['crm_review']['payload']['amount_cents']);
    }

    public function test_c6_cannot_be_selected_if_not_approved_or_healthy(): void
    {
        $p=$this->policy('lowest_cost');
        $p['providers']['c6']['approved']=true;
        self::assertSame('asaas',$this->preview(policy:$p)['gateway_quote']['gateway']);
    }

    public function test_eligible_c6_wins_only_in_synthetic_review(): void
    {
        $p=$this->policy('lowest_cost');
        $p['providers']['c6']['enabled']=true;
        $p['providers']['c6']['approved']=true;
        $p['providers']['c6']['healthy']=true;
        $v=$this->preview(policy:$p);
        self::assertSame('c6',$v['gateway_quote']['gateway']);
        self::assertFalse($v['crm_review']['payload']['can_activate']);
    }

    public function test_fee_policy_revision_cannot_be_forged(): void
    {
        $this->expectException(LogicException::class);
        $this->preview(revision:3);
    }

    public function test_unavailable_channel_cannot_be_charged(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->preview(['chatgpt_ads'],['chatgpt_ads'=>false]);
    }

    public function test_no_payment_method_is_never_silently_replaced(): void
    {
        $this->expectException(RuntimeException::class);
        $this->preview(method:'credit_card');
    }

    public function test_invalid_business_policy_cannot_be_signed(): void
    {
        $p=$this->policy();$p['providers']['asaas']['approved']=false;
        $this->expectException(InvalidArgumentException::class);
        $this->preview(policy:$p);
    }
}
