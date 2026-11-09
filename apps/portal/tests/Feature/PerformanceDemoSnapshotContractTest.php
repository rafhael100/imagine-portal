<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Presentation\Preview\PerformanceDemoSnapshot;
use Tests\TestCase;

final class PerformanceDemoSnapshotContractTest extends TestCase
{
    /** @return array<string,mixed> */
    private function fixture(): array
    {
        return json_decode(
            (string) file_get_contents(base_path('preview/fixtures/performance-dashboard-v1.json')),
            true,
            32,
            JSON_THROW_ON_ERROR
        );
    }

    /** @param array<string,mixed> $payload */
    private function parse(array $payload): ?array
    {
        return PerformanceDemoSnapshot::parse(
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'org-demo-aurora',
            'perf-demo-aurora'
        );
    }

    public function test_versioned_snapshot_is_valid_and_rendered_from_fixture(): void
    {
        $data = $this->fixture();
        $this->assertSame($data, $this->parse($data));
        $this->assertSame($data, PerformanceDemoSnapshot::loadStatic('org-demo-aurora'));
        $this->assertNull(PerformanceDemoSnapshot::loadStatic('org-demo-other'));

        $html = view('portal.demo.pview-navigation')->render();
        $this->assertStringContainsString('data-pview-perf="offline-demo"', $html);
        $this->assertStringContainsString('>264</div>', $html);
        $this->assertStringContainsString('Pesquisa — novos contatos (exemplo)', $html);
        $this->assertStringContainsString('Reconhecimento — marca (exemplo)', $html);
        $this->assertStringContainsString('Pausada (DEMO)', $html);
        $this->assertStringContainsString('Capacidade: indefinida', $html);
        $this->assertStringContainsString('Números fictícios', $html);
        $this->assertSame(8, preg_match_all('/data-screen="[a-z]+"/', $html));
        $this->assertSame(8, preg_match_all('/data-nav="[a-z]+"/', $html));
    }

    public function test_rejects_untrusted_payload_variations_without_partial_data(): void
    {
        $data = $this->fixture();

        $mutations = [
            'wrong_schema' => static function (array &$d): void { $d['schema_version'] = 'production'; },
            'wrong_producer' => static function (array &$d): void { $d['producer'] = 'portal-live'; },
            'wrong_source' => static function (array &$d): void { $d['source'] = 'live'; },
            'foreign_organization' => static function (array &$d): void { $d['portal_organization_ref'] = 'org-demo-b'; },
            'foreign_tenant' => static function (array &$d): void { $d['performance_tenant_ref'] = 'perf-demo-b'; },
            'active_service' => static function (array &$d): void { $d['service_state'] = 'active'; },
            'contracted_service' => static function (array &$d): void { $d['commercial']['contracted'] = true; },
            'fake_unlimited' => static function (array &$d): void { $d['commercial']['capacity_state'] = 'unlimited'; },
            'invented_price' => static function (array &$d): void { $d['commercial']['price_state'] = 'approved'; },
            'sensitive_extra_field' => static function (array &$d): void { $d['access_token'] = 'secret-do-not-render'; },
            'metric_extra_field' => static function (array &$d): void { $d['metrics']['customer_email'] = 'someone@example.test'; },
            'media_spend' => static function (array &$d): void { $d['metrics']['media_spend'] = 1; },
            'negative_clicks' => static function (array &$d): void { $d['metrics']['clicks'] = -1; },
            'string_clicks' => static function (array &$d): void { $d['metrics']['clicks'] = '264'; },
            'fake_campaign_count' => static function (array &$d): void { $d['metrics']['campaigns_managed'] = 3; },
            'fake_approval_count' => static function (array &$d): void { $d['metrics']['approvals_pending'] = 0; },
            'foreign_campaign' => static function (array &$d): void { $d['campaigns'][0]['tenant_ref'] = 'perf-demo-b'; },
            'foreign_approval' => static function (array &$d): void { $d['approvals'][0]['tenant_ref'] = 'perf-demo-b'; },
            'unlisted_channel' => static function (array &$d): void { $d['campaigns'][0]['channel'] = 'tiktok'; },
            'live_campaign_state' => static function (array &$d): void { $d['campaigns'][0]['state'] = 'active'; },
            'not_managed' => static function (array &$d): void { $d['campaigns'][0]['managed'] = false; },
            'duplicate_campaign' => static function (array &$d): void { $d['campaigns'][1]['id'] = $d['campaigns'][0]['id']; },
            'missing_campaign' => static function (array &$d): void { array_pop($d['campaigns']); },
            'unknown_approval_target' => static function (array &$d): void { $d['approvals'][0]['campaign_id'] = 'demo-missing'; },
            'approval_state_unsafe' => static function (array &$d): void { $d['approvals'][0]['status'] = 'executed_real'; },
            'duplicate_approval' => static function (array &$d): void { $d['approvals'][] = $d['approvals'][0]; },
            'unexpected_campaign_key' => static function (array &$d): void { $d['campaigns'][0]['budget'] = 200; },
            'malformed_manifest' => static function (array &$d): void { $d['approvals'][0]['manifest_version'] = 'v1'; },
            'deleted_key' => static function (array &$d): void { unset($d['metrics']['source']); },
            'invalid_campaign_id' => static function (array &$d): void { $d['campaigns'][0]['id'] = '../../etc/passwd'; },
        ];
        foreach ($mutations as $label => $mutate) {
            $invalid = $data;
            $mutate($invalid);
            $this->assertNull($this->parse($invalid), 'Fail-closed contract failed for '.$label);
        }

        $this->assertNull(PerformanceDemoSnapshot::parse('invalid json', 'org-demo-aurora', 'perf-demo-aurora'));
        $this->assertNull(PerformanceDemoSnapshot::parse('{}', 'org-demo-aurora', 'perf-demo-aurora'));
        $this->assertNull(PerformanceDemoSnapshot::parse('', 'org-demo-aurora', 'perf-demo-aurora'));
        $this->assertNull(PerformanceDemoSnapshot::parse(json_encode($data), 'org-demo-aurora', 'perf-demo-b'));
    }

    public function test_no_http_route_or_runtime_approval_is_exposed(): void
    {
        $this->get('/performance')->assertNotFound();
        $this->get('/portal/performance')->assertNotFound();
        $this->get('/portal/performance/approve')->assertNotFound();
        $this->get('/performance/ads')->assertNotFound();

        $html = view('portal.demo.pview-navigation')->render();
        foreach (['<form', '<input', '<button', '<iframe', '<script src', 'fetch(',
            'XMLHttpRequest', 'WebSocket', 'navigator.sendBeacon',
        ] as $disallowed) {
            $this->assertStringNotContainsString($disallowed, $html);
        }
    }
}
