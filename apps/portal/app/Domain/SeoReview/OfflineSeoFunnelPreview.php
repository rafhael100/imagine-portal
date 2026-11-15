<?php
declare(strict_types=1);

namespace App\Domain\SeoReview;

use DateTimeImmutable;
use DateTimeZone;

/**
 * SEO-F106 / Portal — strictly CLI-only and test-only contract consumer.
 *
 * Does NOT authenticate users, verify provider data, authorize any tenant,
 * expose a Laravel route or create grants. Test fixture is example.com.
 */
final readonly class OfflineSeoFunnelPreview
{
    private function __construct(
        public array $period,
        public ?array $searchDiscovery,
        public array $stageAvailability,
        public bool $originUnverified,
    ) {}

    private static function shape(mixed $input, array $expected): bool
    {
        if (!is_array($input) || array_is_list($input)) return false;
        $keys = array_keys($input);
        sort($keys);
        sort($expected);
        return $keys === $expected;
    }

    private static function count(mixed $n): bool
    {
        return is_int($n) && $n >= 0 && $n <= 1000000000;
    }

    private static function finite(mixed $n): bool
    {
        return (is_float($n) || is_int($n)) && is_finite((float) $n);
    }

    private static function date(mixed $value): bool
    {
        if (!is_string($value) ||
            preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D', $value) !== 1) return false;
        [$year, $month, $day] = explode('-', $value);
        return checkdate((int) $month, (int) $day, (int) $year);
    }

    private static function percentOrNull(mixed $n): bool
    {
        // Percentage can exceed 100 when growing from a small baseline.
        return $n === null ||
            (self::finite($n) && $n >= -100 && $n <= 100000000);
    }

    private static function validStages(mixed $stages): bool
    {
        if (!is_array($stages) || !array_is_list($stages) ||
            count($stages) !== 3) return false;
        $config = [
            ['VISITAS','analytics_sessions','authorized_web_analytics',
                'Search Console clicks do not establish visits or sessions.'],
            ['LEADS','attributed_crm_leads','authorized_crm_lead_events',
                'A search click does not establish a recorded lead.'],
            ['CLIENTES','attributed_crm_customers','authorized_crm_customer_events',
                'A lead or search click does not establish a won customer.'],
        ];
        foreach ($config as $i => [$name, $metric, $source, $reason]) {
            $stage = $stages[$i];
            if (!self::shape($stage, [
                'name','metric','value','availability','requiredSource','reason'
            ]) || $stage['name'] !== $name ||
                $stage['metric'] !== $metric ||
                $stage['value'] !== null ||
                $stage['availability'] !== 'NOT_AVAILABLE' ||
                $stage['requiredSource'] !== $source ||
                $stage['reason'] !== $reason) return false;
        }
        return true;
    }

    public static function fromDto(mixed $dto): ?self
    {
        if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'testing' ||
            getenv('PORTAL_SEO_F106_LAB') !== '1') return null;

        if (!self::shape($dto, [
            'contractVersion','methodology','sourceType','scope','period',
            'organicSearchDiscovery','organicSearchDiscoveryStatus',
            'stages','conversionRates','sourceCompletenessNotProven',
            'requiresIndependentTenantPropertyGrant',
            'requiresVerifiedWebAnalytics','requiresAuthorizedCrmAttribution',
            'oauthVerifiedForImagine','portalTenantGrantVerified',
            'customerDisplayAuthorized','productionReadAuthorized',
            'publicationAuthorized','networkPerformed','reviewSignals',
            'releaseStatus','caveats'
        ]) || $dto['contractVersion'] !==
            'seo-visitas-leads-clientes-preview-offline-v1' ||
            $dto['methodology'] !== 'VISITAS_LEADS_CLIENTES' ||
            $dto['sourceType'] !==
            'operator_supplied_gsc_snapshots_not_provider_verified' ||
            $dto['sourceCompletenessNotProven'] !== true ||
            $dto['releaseStatus'] !== 'HOLD_IMAGINE_BACKEND_AUTHORIZATION')
            return null;

        foreach ([
            'oauthVerifiedForImagine','portalTenantGrantVerified',
            'customerDisplayAuthorized','productionReadAuthorized',
            'publicationAuthorized','networkPerformed'
        ] as $flag) if ($dto[$flag] !== false) return null;

        foreach ([
            'requiresIndependentTenantPropertyGrant',
            'requiresVerifiedWebAnalytics','requiresAuthorizedCrmAttribution'
        ] as $flag) if ($dto[$flag] !== true) return null;

        if (!self::shape($dto['scope'], ['tenantRef','propertyFingerprint']) ||
            $dto['scope']['tenantRef'] !== 'demo-tenant' ||
            $dto['scope']['propertyFingerprint'] !==
                hash('sha256','sc-domain:example.com'))
            return null;

        if (!self::validStages($dto['stages']) ||
            !self::shape($dto['conversionRates'], [
                'visitsToLeads','leadsToCustomers','visitsToCustomers'
            ]) || $dto['conversionRates']['visitsToLeads'] !== null ||
            $dto['conversionRates']['leadsToCustomers'] !== null ||
            $dto['conversionRates']['visitsToCustomers'] !== null ||
            $dto['reviewSignals'] !== [])
            return null;

        if (!is_array($dto['caveats']) ||
            !array_is_list($dto['caveats']) ||
            count($dto['caveats']) !== 4) return null;
        foreach ($dto['caveats'] as $caveat)
            if (!is_string($caveat) || trim($caveat) === '' ||
                strlen($caveat) > 320) return null;

        $p = $dto['period'];
        if (!self::shape($p, [
            'startDate','endDate','requestedDays','returnedDays','unreturnedDays'
        ]) || !self::date($p['startDate']) || !self::date($p['endDate']) ||
            $p['endDate'] < $p['startDate'] ||
            !self::count($p['requestedDays']) || $p['requestedDays'] < 1 ||
            $p['requestedDays'] > 92 ||
            !self::count($p['returnedDays']) ||
            !self::count($p['unreturnedDays']) ||
            $p['returnedDays'] + $p['unreturnedDays'] !== $p['requestedDays'])
            return null;

        $start = DateTimeImmutable::createFromFormat(
            '!Y-m-d', $p['startDate'], new DateTimeZone('UTC'));
        $end = DateTimeImmutable::createFromFormat(
            '!Y-m-d', $p['endDate'], new DateTimeZone('UTC'));
        if ($start === false || $end === false ||
            $start->diff($end)->days + 1 !== $p['requestedDays'])
            return null;

        $status = $dto['organicSearchDiscoveryStatus'];
        $discovery = $dto['organicSearchDiscovery'];
        if ($status === 'PARTIAL_DATES_WITHHELD') {
            if ($discovery !== null) return null;
        } elseif ($status === 'SUBMITTED_DATES_ONLY_ORIGIN_UNVERIFIED') {
            if ($p['unreturnedDays'] !== 0 ||
                !self::shape($discovery, [
                    'source','clicks','impressions','ctr',
                    'weightedAveragePosition','clicksChangePercent',
                    'impressionsChangePercent','dataCaveat'
                ]) || $discovery['source'] !==
                    'manual_unverified_gsc_date_rows' ||
                $discovery['dataCaveat'] !==
                    'SUBMITTED_DATES_ONLY_NOT_PROVIDER_VERIFIED' ||
                !self::count($discovery['clicks']) ||
                !self::count($discovery['impressions']) ||
                $discovery['clicks'] > $discovery['impressions'] ||
                !self::finite($discovery['ctr']) ||
                $discovery['ctr'] < 0 || $discovery['ctr'] > 1 ||
                abs($discovery['ctr'] -
                    ($discovery['impressions'] > 0 ?
                    $discovery['clicks'] / $discovery['impressions'] : 0)) > 0.0001 ||
                !self::percentOrNull($discovery['clicksChangePercent']) ||
                !self::percentOrNull($discovery['impressionsChangePercent']) ||
                ($discovery['impressions'] === 0 &&
                    $discovery['weightedAveragePosition'] !== null) ||
                ($discovery['impressions'] > 0 && (
                    !self::finite($discovery['weightedAveragePosition']) ||
                    $discovery['weightedAveragePosition'] < 1
                ))) return null;
        } else {
            return null;
        }

        // Output contains no credentials, tenant ID, property, raw DTO or
        // authority claims. For test UI ONLY: all stages are unavailable.
        return new self(
            [
                'startDate'=>$p['startDate'],
                'endDate'=>$p['endDate'],
                'returnedDays'=>$p['returnedDays'],
                'unreturnedDays'=>$p['unreturnedDays']
            ],
            $discovery === null ? null : [
                'clicks'=>$discovery['clicks'],
                'impressions'=>$discovery['impressions'],
                'ctr'=>$discovery['ctr']
            ],
            [
                'VISITAS'=>'NOT_AVAILABLE',
                'LEADS'=>'NOT_AVAILABLE',
                'CLIENTES'=>'NOT_AVAILABLE'
            ],
            true
        );
    }

    public function hasVerifiedTenantGrant(): bool { return false; }
    public function canQueryGoogle(): bool { return false; }
    public function canPublish(): bool { return false; }
    public function canExposeCustomerData(): bool { return false; }
    public function canStoreTokens(): bool { return false; }
}
