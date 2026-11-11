<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\SeoReview\GoogleSearchConsolePeriodComparison;
use PHPUnit\Framework\TestCase;

/**
 * Pure synthetic Google responses; no authenticated issuer, HTTP or vault.
 */
final class GoogleSearchConsolePeriodComparisonTest extends TestCase
{
    private function compare(): GoogleSearchConsolePeriodComparison
    {
        return new GoogleSearchConsolePeriodComparison();
    }

    private function period(string $first, string $second, int $multiplier = 1): array
    {
        return ['responseAggregationType' => 'byProperty', 'rows' => [
            [
                'keys' => [$second],
                'clicks' => 3.0 * $multiplier,
                'impressions' => 30.0 * $multiplier,
                'ctr' => 0.1,
                'position' => 6.0,
            ],
            [
                'keys' => [$first],
                'clicks' => 2.0 * $multiplier,
                'impressions' => 20.0 * $multiplier,
                'ctr' => 0.1,
                'position' => 4.0,
            ],
        ]];
    }

    private function full(): ?array
    {
        return $this->compare()->compare(
            $this->period('2026-11-01', '2026-11-02'),
            '2026-11-01', '2026-11-02',
            $this->period('2026-11-08', '2026-11-09', 2),
            '2026-11-08', '2026-11-09', 50,
        );
    }

    public function test_equally_sized_nonoverlapping_windows_compare_only_returned_rows(): void
    {
        $result = $this->full();
        self::assertIsArray($result);
        self::assertSame('informational_returned_rows_only', $result['status']);
        self::assertTrue($result['comparable']);
        self::assertSame(2, $result['previous']['returnedDays']);
        self::assertSame(0, $result['current']['unreportedDates']);
        self::assertSame(5.0, $result['previous']['returnedClicks']);
        self::assertSame(10.0, $result['current']['returnedClicks']);
        self::assertSame(5.0, $result['changes']['clicksDelta']);
        self::assertSame(100.0, $result['changes']['clicksChangePercent']);
        self::assertSame(50.0, $result['changes']['impressionsDelta']);
        self::assertSame(100.0, $result['changes']['impressionsChangePercent']);
        self::assertSame(0.0, $result['changes']['ctrDeltaPercentagePoints']);
        self::assertSame(0.0, $result['changes']['averagePositionDelta']);

        self::assertSame('returned_rows_only_not_verified_complete', $result['coverage']);
        self::assertFalse($result['googleOriginVerified']);
        self::assertFalse(GoogleSearchConsolePeriodComparison::authorizesClientReporting());
        // Neither raw dates nor daily query/page keys can leak into a report.
        $json = json_encode($result, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('2026-11-01', $json);
        self::assertStringNotContainsString('2026-11-09', $json);
        self::assertStringNotContainsString('access_token', $json);
    }

    public function test_missing_days_suppress_all_deltas_instead_of_inventing_zero_traffic(): void
    {
        $response = $this->period('2026-11-01', '2026-11-02');
        array_pop($response['rows']);
        $result = $this->compare()->compare(
            $response, '2026-11-01', '2026-11-02',
            $this->period('2026-11-08', '2026-11-09'),
            '2026-11-08', '2026-11-09', 10,
        );
        self::assertIsArray($result);
        self::assertSame('not_comparable_unreported_dates', $result['status']);
        self::assertFalse($result['comparable']);
        self::assertNull($result['changes']);
        self::assertSame(1, $result['previous']['unreportedDates']);

        $empty = $this->compare()->compare(
            [], '2026-11-01', '2026-11-02',
            [], '2026-11-08', '2026-11-09',
        );
        self::assertIsArray($empty);
        self::assertSame('not_comparable_unreported_dates', $empty['status']);
        self::assertNull($empty['changes']);
    }

    public function test_reaching_row_cap_suppresses_growth_even_with_all_days_present(): void
    {
        $result = $this->compare()->compare(
            $this->period('2026-11-01', '2026-11-02'),
            '2026-11-01', '2026-11-02',
            $this->period('2026-11-08', '2026-11-09'),
            '2026-11-08', '2026-11-09', 2,
        );
        self::assertIsArray($result);
        self::assertSame('not_comparable_row_limit', $result['status']);
        self::assertFalse($result['comparable']);
        self::assertNull($result['changes']);
        self::assertTrue($result['previous']['possibleRowLimitTruncation']);
    }

    public function test_percentage_change_from_zero_is_undefined_not_infinity(): void
    {
        $previous = ['rows' => [[
            'keys' => ['2026-11-01'],
            'clicks' => 0,
            'impressions' => 100,
            'ctr' => 0.0,
            'position' => 5.0,
        ]]];
        $current = ['rows' => [[
            'keys' => ['2026-11-08'],
            'clicks' => 2,
            'impressions' => 100,
            'ctr' => 0.02,
            'position' => 4.0,
        ]]];
        $result = $this->compare()->compare(
            $previous, '2026-11-01', '2026-11-01',
            $current, '2026-11-08', '2026-11-08', 10,
        );
        self::assertIsArray($result);
        self::assertTrue($result['comparable']);
        self::assertSame(2.0, $result['changes']['clicksDelta']);
        self::assertNull($result['changes']['clicksChangePercent']);
        self::assertEqualsWithDelta(2.0, $result['changes']['ctrDeltaPercentagePoints'], 1e-9);
        self::assertSame(-1.0, $result['changes']['averagePositionDelta']);

        $zeroImpressions = ['rows' => [[
            'keys' => ['2026-11-01'], 'clicks' => 0,
            'impressions' => 0, 'ctr' => 0, 'position' => 0,
        ]]];
        $result = $this->compare()->compare(
            $zeroImpressions, '2026-11-01', '2026-11-01',
            $current, '2026-11-08', '2026-11-08', 10,
        );
        self::assertIsArray($result);
        self::assertNull($result['changes']['impressionsChangePercent']);
        self::assertNull($result['changes']['ctrDeltaPercentagePoints']);
        self::assertNull($result['changes']['averagePositionDelta']);
    }

    public function test_wrong_periods_and_untrusted_fields_never_pass_comparison(): void
    {
        $previous = $this->period('2026-11-01', '2026-11-02');
        $current = $this->period('2026-11-08', '2026-11-09');

        foreach ([
            ['2026-11-01', '2026-11-02', '2026-11-02', '2026-11-03'],
            ['2026-11-01', '2026-11-02', '2026-11-08', '2026-11-10'],
            ['2026-11-01', '2026-11-02', '2026-10-08', '2026-10-09'],
            ['2026-11-03', '2026-11-01', '2026-11-08', '2026-11-09'],
            ['2026-02-29', '2026-03-01', '2026-11-08', '2026-11-09'],
            ['2026-01-01', '2026-11-02', '2026-11-08', '2026-11-09'],
            [null, '2026-11-02', '2026-11-08', '2026-11-09'],
        ] as [$pStart, $pEnd, $cStart, $cEnd]) {
            self::assertNull($this->compare()->compare(
                $previous, $pStart, $pEnd,
                $current, $cStart, $cEnd,
            ));
        }
        self::assertNull($this->compare()->compare(
            [...$previous, 'refresh_token' => 'synthetic-secret'],
            '2026-11-01', '2026-11-02',
            $current, '2026-11-08', '2026-11-09',
        ));
        self::assertNull($this->compare()->compare(
            $previous, '2026-11-01', '2026-11-02',
            $current, '2026-11-08', '2026-11-09', '250',
        ));
        self::assertNull($this->compare()->compare(
            $previous, '2026-11-01', '2026-11-02',
            $current, '2026-11-08', '2026-11-09', 1,
        ));
    }
}
