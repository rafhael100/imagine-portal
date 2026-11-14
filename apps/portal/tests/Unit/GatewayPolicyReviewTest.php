<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\PaymentRouting\GatewayPolicyReview;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;

final class GatewayPolicyReviewTest extends TestCase
{
    private function policy(int $revision = 1): array
    {
        return [
            'revision' => $revision, 'mode' => 'manual',
            'currency' => 'BRL', 'preferred_gateway' => 'asaas',
            'providers' => [
                'asaas' => [
                    'enabled' => true, 'approved' => true, 'healthy' => true,
                    'fee_version' => 'SIM-asaas-1',
                    'methods' => ['pix' => ['enabled' => true, 'fixed_cents' => 100, 'variable_bps' => 75, 'settlement_days' => 1]],
                ],
                'c6' => [
                    'enabled' => false, 'approved' => false, 'healthy' => false,
                    'fee_version' => 'SIM-c6-0', 'methods' => [],
                ],
            ],
        ];
    }

    public function test_manual_asaas_default_can_be_reviewed_without_activating_it(): void
    {
        $current = $this->policy();
        $next = $this->policy(2);
        $next['mode'] = 'lowest_cost';
        $r = GatewayPolicyReview::review($current, $next, 1, 'reviewer-01', '2026-11-14T19:00:00Z');
        self::assertSame(2, $r['revision']);
        self::assertFalse($r['activation_allowed']);
        self::assertSame(64, strlen($r['candidate_sha256']));
        self::assertNotSame($r['candidate_sha256'], $r['previous_sha256']);
    }

    public function test_stale_update_rejected(): void
    {
        $this->expectException(LogicException::class);
        GatewayPolicyReview::review($this->policy(), $this->policy(2), 3, 'reviewer-01', '2026-11-14T19:00:00Z');
    }

    public function test_c6_cannot_be_enabled_without_contract_and_independent_homologation(): void
    {
        $current = $this->policy();
        $next = $this->policy(2);
        $next['providers']['c6'] = [
            'enabled' => true, 'approved' => true, 'healthy' => true,
            'fee_version' => 'SIM-c6-1',
            'methods' => ['pix' => ['enabled' => true, 'fixed_cents' => 0, 'variable_bps' => 0, 'settlement_days' => 0]],
        ];
        $this->expectException(LogicException::class);
        GatewayPolicyReview::review($current, $next, 1, 'reviewer-01', '2026-11-14T19:00:00Z');
    }

    public function test_c6_requires_five_externally_verified_references(): void
    {
        $current = $this->policy();
        $next = $this->policy(2);
        $next['mode'] = 'lowest_cost';
        $next['providers']['c6'] = [
            'enabled' => true, 'approved' => true, 'healthy' => true,
            'fee_version' => 'SIM-c6-1',
            'methods' => ['pix' => ['enabled' => true, 'fixed_cents' => 0, 'variable_bps' => 0, 'settlement_days' => 0]],
        ];
        $evidence = [
            'contract_approved' => 'TEST-CONTRACT-123',
            'sandbox_homologated' => 'TEST-SANDBOX-123',
            'security_approved' => 'TEST-SECURITY-123',
            'finance_approved' => 'TEST-FINANCE-123',
            'production_access_authorized' => 'TEST-PROD-AUTH-123',
        ];
        $r = GatewayPolicyReview::review($current, $next, 1, 'reviewer-01', '2026-11-14T19:00:00Z', $evidence);
        self::assertFalse($r['activation_allowed']);
    }

    public function test_unknown_payload_keys_are_rejected(): void
    {
        $p = $this->policy();
        $p['secret'] = 'must-not-be-allowed';
        $this->expectException(InvalidArgumentException::class);
        GatewayPolicyReview::validate($p);
    }

    public function test_no_enabled_provider_rejected(): void
    {
        $p = $this->policy();
        $p['providers']['asaas']['enabled'] = false;
        $this->expectException(InvalidArgumentException::class);
        GatewayPolicyReview::validate($p);
    }

    public function test_disabling_the_manual_default_rejected(): void
    {
        $p = $this->policy();
        $p['providers']['asaas']['enabled'] = false;
        $p['providers']['c6']['enabled'] = true;
        $p['providers']['c6']['approved'] = true;
        $p['providers']['c6']['healthy'] = true;
        $p['providers']['c6']['methods']['pix'] = ['enabled' => true, 'fixed_cents' => 1, 'variable_bps' => 1, 'settlement_days' => 1];
        $this->expectException(InvalidArgumentException::class);
        GatewayPolicyReview::validate($p);
    }

    public function test_revision_only_without_effective_change_is_rejected(): void
    {
        $this->expectException(LogicException::class);
        GatewayPolicyReview::review(
            $this->policy(1), $this->policy(2), 1, 'reviewer-01',
            '2026-11-14T19:00:00Z',
        );
    }

}
