<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\SeoReview\GoogleSearchConsoleDailyPerformanceSeries;
use PHPUnit\Framework\TestCase;

final class GoogleSearchConsoleDailyPerformanceSeriesTest extends TestCase
{
    private function builder(): GoogleSearchConsoleDailyPerformanceSeries
    {
        return new GoogleSearchConsoleDailyPerformanceSeries();
    }

    private function rows(): array
    {
        // Search Console sorts by click volume; chronological order is NOT
        // guaranteed by the provider response.
        return ['responseAggregationType'=>'byProperty', 'rows'=>[
            ['keys'=>['2026-11-09'], 'clicks'=>7.0,
                'impressions'=>100.0, 'ctr'=>0.07, 'position'=>6.2],
            ['keys'=>['2026-11-07'], 'clicks'=>2.0,
                'impressions'=>50.0, 'ctr'=>0.04, 'position'=>3.1],
        ]];
    }

    public function test_dates_are_sorted_without_creating_zero_traffic_days(): void
    {
        $series = $this->builder()->build(
            $this->rows(), '2026-11-07', '2026-11-09',
        );

        self::assertIsArray($series);
        self::assertSame(['2026-11-07','2026-11-09'], array_column($series['days'],'date'));
        self::assertSame(2, $series['returnedDays']);
        self::assertSame(1, $series['unreportedDates']);
        self::assertTrue($series['missingDatesNotBackfilled']);
        self::assertSame('returned_dates_only_not_complete', $series['coverage']);
        self::assertFalse($series['googleOriginVerified']);
        self::assertFalse(GoogleSearchConsoleDailyPerformanceSeries::authorizesDashboardPublication());
        self::assertSame(2.0, $series['days'][0]['clicks']);
        self::assertSame(7.0, $series['days'][1]['clicks']);
        self::assertSame(0.07, $series['days'][1]['ctr']);
    }

    public function test_empty_google_response_is_empty_series_not_fabricated_zeros(): void
    {
        $series = $this->builder()->build(
            [], '2026-11-01', '2026-11-03', 3,
        );
        self::assertIsArray($series);
        self::assertSame([], $series['days']);
        self::assertSame(0, $series['returnedDays']);
        self::assertSame(3, $series['unreportedDates']);
        self::assertTrue($series['missingDatesNotBackfilled']);
    }

    public function test_single_day_response_is_valid_when_present(): void
    {
        $row = [
            'keys'=>['2026-11-10'], 'clicks'=>0, 'impressions'=>0,
            'ctr'=>0, 'position'=>0,
        ];
        $series = $this->builder()->build(
            ['rows'=>[$row]], '2026-11-10', '2026-11-10', 1,
        );
        self::assertIsArray($series);
        self::assertSame(1, $series['returnedDays']);
        self::assertSame(0, $series['unreportedDates']);
        self::assertSame(0.0, $series['days'][0]['impressions']);
    }

    public function test_cannot_plot_outside_requested_range_or_duplicate_date(): void
    {
        $reviewer = $this->builder();

        self::assertNull($reviewer->build(
            $this->rows(), '2026-11-08', '2026-11-09',
        ));
        self::assertNull($reviewer->build(
            $this->rows(), '2026-11-07', '2026-11-08',
        ));

        $duplicate = $this->rows();
        $duplicate['rows'][1]['keys'] = ['2026-11-09'];
        self::assertNull($reviewer->build(
            $duplicate, '2026-11-07', '2026-11-09',
        ));

        self::assertNull($reviewer->build(
            $this->rows(), '2026-11-07', '2026-11-09', 1,
        ));
    }

    public function test_dates_calendar_boundaries_and_bad_metadata_fail_closed(): void
    {
        foreach ([
            ['2026-11-09', '2026-11-07'],
            ['2026-02-29', '2026-11-09'],
            ['2026-11-07', '2026-13-01'],
            ['2026-07-01', '2026-11-09'],
            [null, '2026-11-09'],
            ['2026-11-07', null],
            ['2026-11-07', '2026-11-09 00:00'],
        ] as [$start,$end]) {
            self::assertNull($this->builder()->build(
                $this->rows(), $start, $end,
            ));
        }
        self::assertNull($this->builder()->build(
            ['rows'=>$this->rows()['rows'], 'access_token'=>'fake'],
            '2026-11-07','2026-11-09',
        ));
        self::assertNull($this->builder()->build(
            ['rows'=>null], '2026-11-07','2026-11-09',
        ));
        self::assertNull($this->builder()->build(
            ['rows'=>[['keys'=>['2026-11-09'],
                'clicks'=>1.0,'impressions'=>10.0,'ctr'=>INF,'position'=>1.0]]],
            '2026-11-07','2026-11-09',
        ));
    }

    public function test_series_never_contains_query_page_or_credential_fields(): void
    {
        $series = $this->builder()->build(
            $this->rows(), '2026-11-07','2026-11-09',
        );
        self::assertIsArray($series);
        $json = json_encode($series, JSON_THROW_ON_ERROR);
        foreach ([
            'access_token','refresh_token','client_secret',
            'query','page','property','authuser','Bearer','authorization'
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $json, $forbidden);
        }
    }
}
