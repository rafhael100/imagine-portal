<?php

declare(strict_types=1);

namespace App\Presentation\Preview;

use JsonException;

/**
 * Offline fixture reader, NOT an authenticated S2S/tenant authorization path.
 * Rejects incompatible schemas, extra fields, mismatched tenants/organizations,
 * untrusted provenance, non-demo states and malformed metrics (fail closed).
 */
final class PerformanceDemoSnapshot
{
    private const FIXTURE = 'preview/fixtures/performance-dashboard-v1.json';

    /** @return array<string,mixed>|null */
    public static function loadStatic(string $organization = 'org-demo-aurora'): ?array
    {
        $path = dirname(__DIR__, 3).'/'.self::FIXTURE;
        if (!is_file($path) || is_link($path) || !is_readable($path)) {
            return null;
        }
        $size = filesize($path);
        if ($size === false || $size <= 0 || $size > 32768) {
            return null;
        }
        $json = file_get_contents($path);
        if ($json === false) {
            return null;
        }
        return self::parse($json, $organization, 'perf-demo-aurora');
    }

    /** @return array<string,mixed>|null */
    public static function parse(string $json, string $expectedOrganization, string $expectedTenant): ?array
    {
        if (strlen($json) > 32768 || $expectedOrganization === '' || $expectedTenant === '') {
            return null;
        }
        try {
            $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (!is_array($data) || !self::fields($data, [
            'schema_version', 'producer', 'source',
            'portal_organization_ref', 'performance_tenant_ref',
            'service_state', 'commercial', 'metrics', 'campaigns', 'approvals',
        ])) {
            return null;
        }
        if ($data['schema_version'] !== 'perf.portal.snapshot.demo.v1'
            || $data['producer'] !== 'imagine-performance-ai-mock'
            || $data['source'] !== 'synthetic_fixture_only'
            || $data['portal_organization_ref'] !== $expectedOrganization
            || $data['performance_tenant_ref'] !== $expectedTenant
            || $data['service_state'] !== 'onboarding_pending_demo') {
            return null;
        }
        $tenant = $data['performance_tenant_ref'];

        $c = $data['commercial'];
        if (!is_array($c) || !self::fields($c, ['contracted', 'capacity_state', 'price_state'])
            || $c['contracted'] !== false
            || $c['capacity_state'] !== 'unknown_not_for_sale_demo'
            || $c['price_state'] !== 'not_homologated') {
            return null;
        }

        $m = $data['metrics'];
        if (!is_array($m) || !self::fields($m, [
            'source', 'status', 'campaigns_managed', 'clicks', 'approvals_pending', 'media_spend',
        ]) || $m['source'] !== 'synthetic_demo' || $m['status'] !== 'not_real_metrics'
            || $m['media_spend'] !== null
            || !self::nonnegative($m['campaigns_managed'], 20)
            || !self::nonnegative($m['clicks'], 10000000)
            || !self::nonnegative($m['approvals_pending'], 20)) {
            return null;
        }

        $campaigns = $data['campaigns'];
        if (!is_array($campaigns) || !array_is_list($campaigns) || count($campaigns) < 1 || count($campaigns) > 20) {
            return null;
        }
        $knownCampaigns = [];
        $managedCount = 0;
        foreach ($campaigns as $row) {
            if (!is_array($row) || !self::fields($row, [
                'id', 'tenant_ref', 'channel', 'title', 'state', 'managed',
            ]) || !self::demoId($row['id'])
                || $row['tenant_ref'] !== $tenant
                || !in_array($row['channel'], ['google_ads', 'meta'], true)
                || !is_string($row['title']) || mb_strlen($row['title']) > 120
                || !str_contains($row['title'], '(exemplo)')
                || !in_array($row['state'], ['active_demo', 'paused_demo'], true)
                || $row['managed'] !== true
                || isset($knownCampaigns[$row['id']])) {
                return null;
            }
            $knownCampaigns[$row['id']] = true;
            ++$managedCount; // both active and paused consume capacity
        }
        if ($managedCount !== $m['campaigns_managed']) {
            return null;
        }

        $approvals = $data['approvals'];
        if (!is_array($approvals) || !array_is_list($approvals) || count($approvals) > 20) {
            return null;
        }
        $knownApprovals = [];
        $pendingCount = 0;
        foreach ($approvals as $row) {
            if (!is_array($row) || !self::fields($row, [
                'id', 'tenant_ref', 'campaign_id', 'status', 'manifest_version',
            ]) || !self::demoId($row['id'])
                || $row['tenant_ref'] !== $tenant
                || !is_string($row['campaign_id'])
                || !isset($knownCampaigns[$row['campaign_id']])
                || !in_array($row['status'], [
                    'pending_human_demo', 'approved_demo_no_execution', 'rejected_demo',
                ], true)
                || !is_string($row['manifest_version'])
                || !preg_match('/^demo-v[1-9][0-9]*$/D', $row['manifest_version'])
                || isset($knownApprovals[$row['id']])) {
                return null;
            }
            $knownApprovals[$row['id']] = true;
            if ($row['status'] === 'pending_human_demo') {
                ++$pendingCount;
            }
        }
        if ($pendingCount !== $m['approvals_pending']) {
            return null;
        }

        // Only approved presentation fields can reach Blade; no extra keys.
        return $data;
    }

    public static function channelLabel(string $channel): string
    {
        return match ($channel) {
            'google_ads' => 'Google Ads',
            'meta' => 'Meta (Instagram e/ou Facebook)',
            default => 'Canal indisponível',
        };
    }

    public static function stateLabel(string $state): string
    {
        return match ($state) {
            'active_demo' => 'Ativa (DEMO)',
            'paused_demo' => 'Pausada (DEMO)',
            default => 'Desconhecida (DEMO)',
        };
    }

    public static function approvalLabel(string $status): string
    {
        return match ($status) {
            'pending_human_demo' => 'Pendente (DEMO)',
            'approved_demo_no_execution' => 'Aprovada sem execução (DEMO)',
            'rejected_demo' => 'Rejeitada (DEMO)',
            default => 'Estado indisponível',
        };
    }

    /** @param array<string,mixed> $data
     *  @param list<string> $keys */
    private static function fields(array $data, array $keys): bool
    {
        $found = array_keys($data);
        sort($found, SORT_STRING);
        sort($keys, SORT_STRING);
        return $found === $keys;
    }

    private static function demoId(mixed $id): bool
    {
        return is_string($id) && (bool) preg_match('/^demo-[a-z0-9-]{1,100}$/D', $id);
    }

    private static function nonnegative(mixed $n, int $max): bool
    {
        return is_int($n) && $n >= 0 && $n <= $max;
    }
}
