<?php
declare(strict_types=1);

namespace App\Domain\SeoReview;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Portal SEO/F104: a TEST-ONLY consumer of a date-only SEO Engine snapshot.
 *
 * It is not a Google client, OAuth grant or production dashboard. It only
 * accepts the fixed synthetic tenant/property fixture in a CLI test runner.
 * Never persist, expose as an HTTP route or treat this DTO as an authority.
 */
final readonly class OfflineOpenSeoDailyPreview
{
    private function __construct(
        public array $period,
        public array $summary,
        public bool $missingDatesUnknown,
    ) {}

    private static function shape(mixed $value, array $fields): bool
    {
        if (!is_array($value) || array_is_list($value)) return false;
        $keys = array_keys($value);
        sort($keys);
        sort($fields);
        return $keys === $fields;
    }

    private static function intCount(mixed $value, int $limit = 1000000000): bool
    {
        return is_int($value) && $value >= 0 && $value <= $limit;
    }

    private static function number(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value);
    }

    private static function day(mixed $value): bool
    {
        if (!is_string($value) ||
            preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D', $value) !== 1)
            return false;
        [$y, $m, $d] = explode('-', $value);
        return checkdate((int) $m, (int) $d, (int) $y);
    }

    /**
     * Strictly synthetic DTO. NULL means fail-closed, never "no traffic".
     */
    public static function fromDto(mixed $dto): ?self
    {
        if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'testing' ||
            getenv('PORTAL_OPENSEO_DAILY_LAB') !== '1') return null;

        if (!self::shape($dto, [
            'contractVersion','sourceType','tenantRef','propertyFingerprint',
            'period','summary','daily','coverage','sourceCompletenessNotProven',
            'oauthVerifiedForImagine','portalTenantGrantVerified',
            'customerDisplayAuthorized','productionReadAuthorized',
            'networkPerformed','releaseStatus','caveats',
        ]) ||
            $dto['contractVersion'] !== 'seo-openseo-daily-offline-review-v1' ||
            $dto['sourceType'] !== 'operator_supplied_openseo_date_snapshot_not_provider_verified' ||
            $dto['tenantRef'] !== 'imagine-demo' ||
            $dto['propertyFingerprint'] !== hash('sha256', 'sc-domain:example.com') ||
            $dto['coverage'] !== 'returned_dates_only_missing_dates_unknown' ||
            $dto['sourceCompletenessNotProven'] !== true ||
            $dto['releaseStatus'] !== 'HOLD_IMAGINE_BACKEND_AUTHORIZATION')
            return null;

        foreach ([
            'oauthVerifiedForImagine','portalTenantGrantVerified',
            'customerDisplayAuthorized','productionReadAuthorized','networkPerformed',
        ] as $flag) if ($dto[$flag] !== false) return null;

        if (!is_array($dto['caveats']) || !array_is_list($dto['caveats']) ||
            count($dto['caveats']) !== 3) return null;
        foreach ($dto['caveats'] as $caveat) {
            if (!is_string($caveat) || $caveat === '' ||
                strlen($caveat) > 260) return null;
        }

        $p = $dto['period'];
        if (!self::shape($p, [
            'startDate','endDate','requestedDays','returnedDays','unreturnedDays'
        ]) || !self::day($p['startDate']) || !self::day($p['endDate']) ||
            $p['endDate'] < $p['startDate'] ||
            !self::intCount($p['requestedDays'], 92) ||
            $p['requestedDays'] < 1 ||
            !self::intCount($p['returnedDays'], 92) ||
            !self::intCount($p['unreturnedDays'], 92) ||
            $p['returnedDays'] + $p['unreturnedDays'] !== $p['requestedDays'])
            return null;

        $start = DateTimeImmutable::createFromFormat(
            '!Y-m-d', $p['startDate'], new DateTimeZone('UTC')
        );
        $end = DateTimeImmutable::createFromFormat(
            '!Y-m-d', $p['endDate'], new DateTimeZone('UTC')
        );
        if ($start === false || $end === false ||
            $start->diff($end)->days + 1 !== $p['requestedDays']) return null;

        $rows = $dto['daily'];
        if (!is_array($rows) || !array_is_list($rows) ||
            count($rows) !== $p['returnedDays']) return null;

        $totalClicks = 0;
        $totalImpressions = 0;
        $positionWeighted = 0.0;
        $lastDay = '';
        foreach ($rows as $row) {
            if (!self::shape($row, [
                'date','clicks','impressions','ctr','averagePosition'
            ]) || !self::day($row['date']) ||
                $row['date'] <= $lastDay ||
                $row['date'] < $p['startDate'] ||
                $row['date'] > $p['endDate'] ||
                !self::intCount($row['clicks']) ||
                !self::intCount($row['impressions']) ||
                $row['clicks'] > $row['impressions'] ||
                !self::number($row['ctr']) ||
                $row['ctr'] < 0 || $row['ctr'] > 1 ||
                abs((float) $row['ctr'] -
                    ($row['impressions'] > 0 ?
                        $row['clicks'] / $row['impressions'] : 0)) > 0.0001 ||
                ($row['impressions'] === 0 && $row['averagePosition'] !== null) ||
                ($row['impressions'] > 0 && (
                    !self::number($row['averagePosition']) ||
                    $row['averagePosition'] < 1
                )))
                return null;
            $lastDay = $row['date'];
            $totalClicks += $row['clicks'];
            $totalImpressions += $row['impressions'];
            $positionWeighted += $row['impressions'] *
                ($row['averagePosition'] ?? 0);
        }

        $s = $dto['summary'];
        if (!self::shape($s, [
            'clicks','impressions','ctr','weightedAveragePosition'
        ]) || !self::intCount($s['clicks']) ||
            !self::intCount($s['impressions']) ||
            $s['clicks'] !== $totalClicks ||
            $s['impressions'] !== $totalImpressions ||
            !self::number($s['ctr']) || $s['ctr'] < 0 || $s['ctr'] > 1 ||
            abs((float) $s['ctr'] -
                ($totalImpressions > 0 ? $totalClicks / $totalImpressions : 0)) > 0.0001 ||
            ($totalImpressions === 0 && $s['weightedAveragePosition'] !== null) ||
            ($totalImpressions > 0 && (
                !self::number($s['weightedAveragePosition']) ||
                $s['weightedAveragePosition'] < 1 ||
                abs((float) $s['weightedAveragePosition'] -
                    $positionWeighted / $totalImpressions) > 0.011
            )))
            return null;

        // Only bounded aggregate fields, never the raw provider DTO.
        return new self(
            [
                'startDate'=>$p['startDate'],
                'endDate'=>$p['endDate'],
                'returnedDays'=>$p['returnedDays'],
                'unreturnedDays'=>$p['unreturnedDays'],
            ],
            [
                'clicks'=>$totalClicks,
                'impressions'=>$totalImpressions,
                'ctr'=>$totalImpressions > 0 ?
                    round($totalClicks / $totalImpressions, 4) : 0,
            ],
            $p['unreturnedDays'] > 0,
        );
    }

    public function canQueryGoogle(): bool { return false; }
    public function canPublish(): bool { return false; }
    public function canStoreTokens(): bool { return false; }
    public function hasVerifiedTenantGrant(): bool { return false; }
    public function isCustomerDisplayAuthorized(): bool { return false; }
}
