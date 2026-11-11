<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\SeoReview\GoogleSearchConsoleMetricsReview;
use PHPUnit\Framework\TestCase;

/**
 * No credential, Google HTTP, operational tenant grant or raw query is used.
 * All tests use synthetic responses, not observed search traffic.
 */
final class GoogleSearchConsoleMetricsReviewTest extends TestCase
{
    private function reviewer(): GoogleSearchConsoleMetricsReview
    {
        return new GoogleSearchConsoleMetricsReview();
    }

    public function test_summary_uses_only_returned_rows_and_does_not_leak_keys(): void
    {
        $fixture = ['responseAggregationType' => 'byProperty', 'rows' => [
            ['keys' => ['2026-11-08'], 'clicks' => 4.0,
                'impressions' => 100.0, 'ctr' => 0.04, 'position' => 4.0],
            ['keys' => ['2026-11-09'], 'clicks' => 3.0,
                'impressions' => 20.0, 'ctr' => 0.15, 'position' => 10.0],
        ]];

        $summary = $this->reviewer()->summarize($fixture, ['date'], 250);
        self::assertIsArray($summary);
        self::assertSame(2, $summary['rowCount']);
        self::assertSame(7.0, $summary['returnedClicks']);
        self::assertSame(120.0, $summary['returnedImpressions']);
        self::assertEqualsWithDelta(7/120, $summary['returnedCtr'], 1e-12);
        self::assertSame(5.0, $summary['returnedImpressionWeightedPosition']);
        self::assertFalse($summary['possibleRowLimitTruncation']);
        self::assertSame('returned_rows_only_not_complete', $summary['coverage']);
        self::assertFalse($summary['googleOriginVerified']);
        self::assertFalse(GoogleSearchConsoleMetricsReview::authorizesReporting());

        $serialized = json_encode($summary, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('2026-11-08', $serialized);
        self::assertStringNotContainsString('2026-11-09', $serialized);
        self::assertStringNotContainsString('keys', $serialized);
    }

    public function test_empty_response_does_not_invent_traffic_or_position(): void
    {
        foreach ([[], ['rows'=>[]], ['rows'=>[], 'responseAggregationType'=>'auto']] as $input) {
            $summary = $this->reviewer()->summarize($input, ['date'], 1);
            self::assertIsArray($summary);
            self::assertSame(0, $summary['rowCount']);
            self::assertSame(0.0, $summary['returnedClicks']);
            self::assertSame(0.0, $summary['returnedImpressions']);
            self::assertNull($summary['returnedCtr']);
            self::assertNull($summary['returnedImpressionWeightedPosition']);
            self::assertFalse($summary['possibleRowLimitTruncation']);
        }
    }

    public function test_at_row_limit_warns_possible_incomplete_coverage(): void
    {
        $response = ['rows' => [[
            'keys'=>['mobile'],
            'clicks'=>12,
            'impressions'=>200,
            'ctr'=>0.06,
            'position'=>3.25,
        ]]];
        $summary = $this->reviewer()->summarize($response, ['device'], 1);
        self::assertIsArray($summary);
        self::assertTrue($summary['possibleRowLimitTruncation']);
        self::assertSame('returned_rows_only_not_complete', $summary['coverage']);
        self::assertSame(12.0, $summary['returnedClicks']);
    }

    public function test_dimensionless_aggregate_must_not_return_raw_queries(): void
    {
        $response = ['rows' => [[
            'clicks'=>1,
            'impressions'=>10,
            'ctr'=>0.1,
            'position'=>4,
        ]]];
        $summary = $this->reviewer()->summarize($response, [], 10);
        self::assertIsArray($summary);
        self::assertSame(1, $summary['rowCount']);

        $response['rows'][0]['keys'] = ['private search query'];
        self::assertNull($this->reviewer()->summarize($response, [], 10));
    }

    public function test_malformed_responses_are_rejected_instead_of_publishing_metrics(): void
    {
        $valid = [
            'rows'=>[[
                'keys'=>['2026-11-08'], 'clicks'=>4.0,
                'impressions'=>100.0, 'ctr'=>0.04, 'position'=>4.0,
            ]],
        ];
        $badRows = [
            ['keys'=>['2026-11-08'], 'clicks'=>-1.0,
                'impressions'=>100.0, 'ctr'=>0.04, 'position'=>4.0],
            ['keys'=>['2026-11-08'], 'clicks'=>'4',
                'impressions'=>100.0, 'ctr'=>0.04, 'position'=>4.0],
            ['keys'=>['2026-11-08'], 'clicks'=>NAN,
                'impressions'=>100.0, 'ctr'=>0.04, 'position'=>4.0],
            ['keys'=>['2026-11-08'], 'clicks'=>INF,
                'impressions'=>100.0, 'ctr'=>0.04, 'position'=>4.0],
            ['keys'=>['2026-11-08'], 'clicks'=>false,
                'impressions'=>100.0, 'ctr'=>0.04, 'position'=>4.0],
            ['keys'=>['2026-11-08'], 'clicks'=>4.0,
                'impressions'=>-1.0, 'ctr'=>0.04, 'position'=>4.0],
            ['keys'=>['2026-11-08'], 'clicks'=>4.0,
                'impressions'=>100.0, 'ctr'=>1.1, 'position'=>4.0],
            ['keys'=>['2026-11-08'], 'clicks'=>4.0,
                'impressions'=>100.0, 'ctr'=>0.8, 'position'=>4.0],
            ['keys'=>['2026-11-08'], 'clicks'=>1.0,
                'impressions'=>0.0, 'ctr'=>0.0, 'position'=>4.0],
            ['keys'=>['2026-11-08'], 'clicks'=>4.0,
                'impressions'=>100.0, 'ctr'=>0.04, 'position'=>-2],
            ['keys'=>['2026-11-08'], 'clicks'=>4.0,
                'impressions'=>100.0, 'ctr'=>0.04, 'position'=>10001],
            ['keys'=>['2026-11-08'], 'clicks'=>4.0,
                'impressions'=>100.0, 'ctr'=>0.04, 'position'=>4.0,
                'access_token'=>'secret'],
            ['keys'=>['2026-02-29'], 'clicks'=>4,
                'impressions'=>100, 'ctr'=>0.04, 'position'=>4],
            ['keys'=>['2026-11-08'."\n"], 'clicks'=>4,
                'impressions'=>100, 'ctr'=>0.04, 'position'=>4],
            ['keys'=>["\xC3"], 'clicks'=>4,
                'impressions'=>100, 'ctr'=>0.04, 'position'=>4],
            ['keys'=>[], 'clicks'=>4,
                'impressions'=>100, 'ctr'=>0.04, 'position'=>4],
        ];
        foreach ($badRows as $row) {
            self::assertNull($this->reviewer()->summarize(['rows'=>[$row]], ['date']));
        }

        foreach ([
            null, 'synthetic', false,
            ['rows'=>[['clicks'=>1]]],
            ['rows'=>'invalid'],
            ['rows'=>null],
            ['rows'=>[...$valid['rows'], ...$valid['rows']]],
            ['rows'=>[$valid['rows'][0], $valid['rows'][0]]],
            ['rows'=>$valid['rows'], 'refresh_token'=>'secret'],
            ['rows'=>$valid['rows'], 'responseAggregationType'=>'unexpected'],
            ['rows'=>$valid['rows'], 'metadata'=>['first_incomplete_date'=>'2026-11-09']],
        ] as $invalid) {
            self::assertNull($this->reviewer()->summarize($invalid, ['date'], 250));
        }
        self::assertNull($this->reviewer()->summarize($valid, ['date'], 0));
        self::assertNull($this->reviewer()->summarize($valid, ['date'], 1001));
        self::assertNull($this->reviewer()->summarize($valid, ['date', 'date']));
        // Query text may legitimately look like a date; semantic date
        // validation applies only to the explicit date dimension.
        self::assertIsArray($this->reviewer()->summarize($valid, ['query']));
        self::assertNull($this->reviewer()->summarize($valid, ['hour']));
        self::assertNull($this->reviewer()->summarize($valid, 'date'));
    }
}
