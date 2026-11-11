<?php

declare(strict_types=1);

namespace App\Domain\SeoReview;

use DateTimeImmutable;
use DateTimeZone;

/**
 * GSC-F128: offline, non-operational daily chart data from Search Analytics.
 *
 * Google omits dates with no returned data. Do NOT manufacture zero-traffic
 * points for those dates. Parsing here never authenticates Google, Portal,
 * tenant grants, MFA, property ownership or token access.
 */
final class GoogleSearchConsoleDailyPerformanceSeries
{
    /**
     * @return array{
     *   days:list<array{date:string,clicks:float,impressions:float,ctr:float,position:float}>,
     *   returnedDays:int,
     *   unreportedDates:int,
     *   missingDatesNotBackfilled:bool,
     *   coverage:string,
     *   googleOriginVerified:bool
     * }|null
     */
    public function build(
        #[\SensitiveParameter] mixed $response,
        mixed $startDate,
        mixed $endDate,
        mixed $rowLimit = 250,
    ): ?array {
        if (!self::validDate($startDate)
            || !self::validDate($endDate)
            || $startDate > $endDate
            || !is_int($rowLimit)
            || $rowLimit < 1
            || $rowLimit > 1000) {
            return null;
        }

        $start = DateTimeImmutable::createFromFormat(
            '!Y-m-d', $startDate, new DateTimeZone('UTC'),
        );
        $end = DateTimeImmutable::createFromFormat(
            '!Y-m-d', $endDate, new DateTimeZone('UTC'),
        );
        if ($start === false || $end === false
            || $start->diff($end)->days > 92) {
            return null;
        }

        // Reuse F127 to reject malformed response bodies, unauthorized
        // extra keys, malformed numerical metrics, NaN/INF and duplicates.
        if ((new GoogleSearchConsoleMetricsReview())->summarize(
            $response, ['date'], $rowLimit,
        ) === null) {
            return null;
        }

        $rows = $response['rows'] ?? [];
        $days = [];
        foreach ($rows as $row) {
            $date = $row['keys'][0];
            if ($date < $startDate || $date > $endDate) {
                return null; // never leak a date outside the requested window
            }

            $days[] = [
                'date' => $date,
                'clicks' => (float)$row['clicks'],
                'impressions' => (float)$row['impressions'],
                'ctr' => (float)$row['ctr'],
                'position' => (float)$row['position'],
            ];
        }

        usort($days, static fn(array $a, array $b): int =>
            strcmp($a['date'], $b['date']));

        $expectedDays = (int)$start->diff($end)->days + 1;

        return [
            'days' => $days,
            'returnedDays' => count($days),
            'unreportedDates' => $expectedDays - count($days),
            'missingDatesNotBackfilled' => true,
            'coverage' => 'returned_dates_only_not_complete',
            'googleOriginVerified' => false,
        ];
    }

    public static function authorizesDashboardPublication(): bool
    {
        return false;
    }

    private static function validDate(mixed $input): bool
    {
        if (!is_string($input)
            || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D', $input) !== 1) {
            return false;
        }

        $values = explode('-', $input);

        return checkdate((int)$values[1], (int)$values[2], (int)$values[0]);
    }
}
