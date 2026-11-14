<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\PaymentRouting\GatewayRoutingPreview;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class GatewayRoutingPreviewTest extends TestCase
{
    private function policy(string $mode = 'manual'): array
    {
        return [
            'mode' => $mode,
            'currency' => 'BRL',
            'preferred_gateway' => 'asaas',
            'providers' => [
                'asaas' => [
                    'enabled' => true, 'approved' => true, 'healthy' => true,
                    'fee_version' => 'TEST-asaas-v1',
                    'methods' => [
                        'pix' => ['enabled' => true, 'fixed_cents' => 100, 'variable_bps' => 100, 'settlement_days' => 1],
                        'boleto' => ['enabled' => true, 'fixed_cents' => 240, 'variable_bps' => 0, 'settlement_days' => 2],
                    ],
                ],
                'c6' => [
                    'enabled' => false, 'approved' => false, 'healthy' => false,
                    'fee_version' => 'TEST-c6-v1',
                    'methods' => [
                        'pix' => ['enabled' => true, 'fixed_cents' => 30, 'variable_bps' => 60, 'settlement_days' => 1],
                    ],
                ],
            ],
        ];
    }

    public function test_asaas_is_initial_default_for_pix_without_live_calls(): void
    {
        $q = GatewayRoutingPreview::quote($this->policy(), 'pix', 100_00);
        self::assertSame('asaas', $q['gateway']);
        self::assertSame(200, $q['estimated_fee_cents']);
        self::assertSame(9800, $q['estimated_net_cents']);
        self::assertSame('TEST-asaas-v1', $q['fee_version']);
    }

    public function test_lowest_cost_only_considers_explicitly_approved_healthy_gateway(): void
    {
        $p = $this->policy('lowest_cost');
        self::assertSame('asaas', GatewayRoutingPreview::quote($p, 'pix', 100_00)['gateway']);
        $p['providers']['c6']['enabled'] = true;
        $p['providers']['c6']['approved'] = true;
        self::assertSame('asaas', GatewayRoutingPreview::quote($p, 'pix', 100_00)['gateway']);
        $p['providers']['c6']['healthy'] = true;
        self::assertSame('c6', GatewayRoutingPreview::quote($p, 'pix', 100_00)['gateway']);
        self::assertSame(90, GatewayRoutingPreview::quote($p, 'pix', 100_00)['estimated_fee_cents']);
        // Provider selected for Pix does not imply eligibility for boleto.
        self::assertSame('asaas', GatewayRoutingPreview::quote($p, 'boleto', 100_00)['gateway']);
    }

    public function test_manual_mode_never_silently_falls_back_to_another_psp(): void
    {
        $p = $this->policy();
        $p['preferred_gateway'] = 'c6';
        $this->expectException(RuntimeException::class);
        GatewayRoutingPreview::quote($p, 'pix', 100_00);
    }

    public function test_empty_or_unapproved_terms_fail_closed(): void
    {
        $p = $this->policy('lowest_cost');
        $p['providers']['asaas']['methods']['pix']['variable_bps'] = '100';
        $this->expectException(RuntimeException::class);
        GatewayRoutingPreview::quote($p, 'pix', 100_00);
    }

    public function test_integer_rounding_is_stable_for_fractional_cents(): void
    {
        $p = $this->policy();
        $p['providers']['asaas']['methods']['pix']['fixed_cents'] = 0;
        $p['providers']['asaas']['methods']['pix']['variable_bps'] = 1;
        self::assertSame(1, GatewayRoutingPreview::quote($p, 'pix', 10_000)['estimated_fee_cents']);
    }

    public function test_unknown_method_and_currency_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        GatewayRoutingPreview::quote($this->policy(), 'wire', 100);
    }

    public function test_charge_cannot_switch_psp_after_creation(): void
    {
        self::assertSame('asaas', GatewayRoutingPreview::enforcePinnedGateway('asaas', 'asaas'));
        $this->expectException(LogicException::class);
        GatewayRoutingPreview::enforcePinnedGateway('asaas', 'c6');
    }

    public function test_fees_at_or_above_gross_cannot_be_selected(): void
    {
        $p = $this->policy();
        $p['providers']['asaas']['methods']['pix']['fixed_cents'] = 100;
        $this->expectException(RuntimeException::class);
        GatewayRoutingPreview::quote($p, 'pix', 50);
    }

    public function test_lowest_cost_skips_provider_with_nonpositive_net(): void
    {
        $p = $this->policy('lowest_cost');
        $p['providers']['asaas']['methods']['pix']['fixed_cents'] = 999;
        $p['providers']['c6']['enabled'] = true;
        $p['providers']['c6']['approved'] = true;
        $p['providers']['c6']['healthy'] = true;
        self::assertSame('c6', GatewayRoutingPreview::quote($p, 'pix', 100)['gateway']);
    }

    public function test_unapproved_c6_cannot_be_selected_even_if_cheaper(): void
    {
        $p = $this->policy('lowest_cost');
        $p['providers']['c6']['enabled'] = true;
        $p['providers']['c6']['healthy'] = true;
        self::assertSame('asaas', GatewayRoutingPreview::quote($p, 'pix', 100_00)['gateway']);
    }

    public function test_lowest_cost_tie_prefers_configured_gateway(): void
    {
        $p = $this->policy('lowest_cost');
        $p['providers']['c6']['enabled'] = true;
        $p['providers']['c6']['approved'] = true;
        $p['providers']['c6']['healthy'] = true;
        $p['providers']['c6']['methods']['pix'] = $p['providers']['asaas']['methods']['pix'];
        self::assertSame('asaas', GatewayRoutingPreview::quote($p, 'pix', 100_00)['gateway']);
    }
}
