<?php

declare(strict_types=1);

namespace App\Domain\SeoReview;

use DateTimeImmutable;
use DateTimeZone;

/**
 * GSC-F129: OFFLINE comparison of two equal-length Search Analytics periods.
 *
 * A returned row is not proof that Google's response is authentic or that
 * Search Analytics returned every row. Never promote this DTO to a client
 * report without a separate trusted Portal + Google authorization boundary.
 *
 * Even "comparable" means comparable RETURNED ROWS only, never the complete
 * traffic of a property. No raw dimension keys or tokens are returned.
 */
final class GoogleSearchConsolePeriodComparison
{
    /**
     * @return array{
     *   status:string,
     *   comparable:bool,
     *   previous:array<string,mixed>,
     *   current:array<string,mixed>,
     *   changes:array<string,float|null>|null,
     *   coverage:string,
     *   googleOriginVerified:bool
     * }|null
     */
    public function compare(
        #[\SensitiveParameter] mixed $previousResponse,
        mixed $previousStart,
        mixed $previousEnd,
        #[\SensitiveParameter] mixed $currentResponse,
        mixed $currentStart,
        mixed $currentEnd,
        mixed $rowLimit = 250,
    ): ?array {
        $daily = new GoogleSearchConsoleDailyPerformanceSeries();
        $previousSeries = $daily->build(
            $previousResponse, $previousStart, $previousEnd, $rowLimit,
        );
        $currentSeries = $daily->build(
            $currentResponse, $currentStart, $currentEnd, $rowLimit,
        );

        if ($previousSeries === null || $currentSeries === null) {
            return null;
        }

        // Calendar lengths rather than raw numeric day counts: both windows
        // include their boundaries. No overlaps or reversed comparisons.
        $previousFrom = DateTimeImmutable::createFromFormat(
            '!Y-m-d', $previousStart, new DateTimeZone('UTC'),
        );
        $previousUntil = DateTimeImmutable::createFromFormat(
            '!Y-m-d', $previousEnd, new DateTimeZone('UTC'),
        );
        $currentFrom = DateTimeImmutable::createFromFormat(
            '!Y-m-d', $currentStart, new DateTimeZone('UTC'),
        );
        $currentUntil = DateTimeImmutable::createFromFormat(
            '!Y-m-d', $currentEnd, new DateTimeZone('UTC'),
        );
        if ($previousFrom === false || $previousUntil === false
            || $currentFrom === false || $currentUntil === false
            || $previousUntil >= $currentFrom
            || $previousFrom->diff($previousUntil)->days
                !== $currentFrom->diff($currentUntil)->days) {
            return null;
        }

        $metrics = new GoogleSearchConsoleMetricsReview();
        $previous = $metrics->summarize($previousResponse, ['date'], $rowLimit);
        $current = $metrics->summarize($currentResponse, ['date'], $rowLimit);
        if ($previous === null || $current === null) {
            return null;
        }

        $hasMissingDates = $previousSeries['unreportedDates'] > 0
            || $currentSeries['unreportedDates'] > 0;
        $hitLimit = $previous['possibleRowLimitTruncation']
            || $current['possibleRowLimitTruncation'];

        // Suppress all deltas when either period has an unknown number of
        // missing days or reaches the row cap. Do not infer zeros.
        $status = $hasMissingDates
            ? 'not_comparable_unreported_dates'
            : ($hitLimit ? 'not_comparable_row_limit'
                : 'informational_returned_rows_only');

        $snapshot = static function (array $summary, array $series): array {
            return [
                'returnedDays' => $series['returnedDays'],
                'unreportedDates' => $series['unreportedDates'],
                'returnedClicks' => $summary['returnedClicks'],
                'returnedImpressions' => $summary['returnedImpressions'],
                'returnedCtr' => $summary['returnedCtr'],
                'returnedImpressionWeightedPosition' =>
                    $summary['returnedImpressionWeightedPosition'],
                'possibleRowLimitTruncation' => $summary['possibleRowLimitTruncation'],
            ];
        };

        $isComparable = !$hasMissingDates && !$hitLimit;

        return [
            'status' => $status,
            'comparable' => $isComparable,
            'previous' => $snapshot($previous, $previousSeries),
            'current' => $snapshot($current, $currentSeries),
            'changes' => $isComparable ? [
                'clicksDelta' => $current['returnedClicks'] - $previous['returnedClicks'],
                'clicksChangePercent' => self::changePercent(
                    $previous['returnedClicks'], $current['returnedClicks'],
                ),
                'impressionsDelta' =>
                    $current['returnedImpressions'] - $previous['returnedImpressions'],
                'impressionsChangePercent' => self::changePercent(
                    $previous['returnedImpressions'], $current['returnedImpressions'],
                ),
                'ctrDeltaPercentagePoints' =>
                    $previous['returnedCtr'] === null || $current['returnedCtr'] === null
                        ? null : ($current['returnedCtr'] - $previous['returnedCtr']) * 100,
                'averagePositionDelta' =>
                    $previous['returnedImpressionWeightedPosition'] === null
                        || $current['returnedImpressionWeightedPosition'] === null
                        ? null : $current['returnedImpressionWeightedPosition']
                            - $previous['returnedImpressionWeightedPosition'],
            ] : null,
            'coverage' => 'returned_rows_only_not_verified_complete',
            'googleOriginVerified' => false,
        ];
    }

    public static function authorizesClientReporting(): bool
    {
        return false;
    }

    private static function changePercent(float $previous, float $current): ?float
    {
        // Change from a zero baseline is undefined, NOT infinite growth.
        return $previous > 0
            ? ($current - $previous) * 100.0 / $previous
            : null;
    }
}
