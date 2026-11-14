<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\PaymentRouting\GatewayRoutingPreview;
use App\Domain\PaymentRouting\PortalGatewayReviewExporter;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PortalGatewayReviewExporterTest extends TestCase
{
    private const KEY = 'synthetic-gateway-crossproduct-review-key-2026-11';
    private const EXPECTED_MAC = '7a6738f4a62c15ab7748c7e31fc07b74cf0b2ec1bbf01ae13b48ab1d5b63fc31';

    private function quote(): array
    {
        return GatewayRoutingPreview::quote([
            'mode' => 'manual', 'currency' => 'BRL', 'preferred_gateway' => 'asaas',
            'providers' => [
                'asaas' => [
                    'enabled' => true, 'approved' => true, 'healthy' => true,
                    'fee_version' => 'SIM-ASAAS-01',
                    'methods' => [
                        'pix' => [
                            'enabled' => true, 'fixed_cents' => 100,
                            'variable_bps' => 75, 'settlement_days' => 1,
                        ],
                    ],
                ],
                'c6' => ['enabled' => false, 'approved' => false, 'healthy' => false,
                    'fee_version' => 'SIM-C6-01', 'methods' => []],
            ],
        ], 'pix', 10000);
    }

    public function test_cross_product_contract_matches_crm_known_hmac_fixture(): void
    {
        $output = PortalGatewayReviewExporter::export(
            $this->quote(), 'org-synthetic-001', 'order-synthetic-001', 1, self::KEY,
        );
        self::assertSame(175, $output['payload']['estimated_fee_cents']);
        self::assertSame(self::EXPECTED_MAC, $output['mac']);
        self::assertFalse($output['payload']['can_charge']);
        self::assertFalse($output['payload']['can_activate']);
    }

    public function test_review_cannot_export_with_unsafe_or_missing_secret(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PortalGatewayReviewExporter::export($this->quote(), 'org-synthetic-001', 'order-synthetic-001', 1, 'x');
    }

    public function test_missing_fee_version_is_blocked(): void
    {
        $quote = $this->quote();
        unset($quote['fee_version']);
        $this->expectException(InvalidArgumentException::class);
        PortalGatewayReviewExporter::export($quote, 'org-synthetic-001', 'order-synthetic-001', 1, self::KEY);
    }
}
