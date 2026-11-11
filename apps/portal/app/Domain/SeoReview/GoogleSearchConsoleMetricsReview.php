<?php

declare(strict_types=1);

namespace App\Domain\SeoReview;

/**
 * GSC-F127: inspect and aggregate the returned rows of a FUTURE trusted
 * Search Analytics response. Never claim these are complete site totals.
 *
 * Google may return top rows only. Input data here is UNTRUSTED and no
 * provider, session, tenant, MFA or property authorization is performed.
 * Keys (queries, page URLs, countries) are validated but never returned.
 */
final class GoogleSearchConsoleMetricsReview
{
    private const DIMENSIONS = ['date', 'page', 'query', 'country', 'device'];
    private const AGGREGATIONS = ['auto', 'byPage', 'byProperty'];

    /**
     * @return array{
     *   rowCount:int,
     *   returnedClicks:float,
     *   returnedImpressions:float,
     *   returnedCtr:float|null,
     *   returnedImpressionWeightedPosition:float|null,
     *   possibleRowLimitTruncation:bool,
     *   coverage:string,
     *   googleOriginVerified:bool
     * }|null
     */
    public function summarize(
        #[\SensitiveParameter] mixed $response,
        mixed $dimensions = ['date'],
        mixed $rowLimit = 250,
    ): ?array {
        if (!is_array($response)
            || !is_array($dimensions)
            || !array_is_list($dimensions)
            || count($dimensions) > 3
            || !is_int($rowLimit)
            || $rowLimit < 1
            || $rowLimit > 1000) {
            return null;
        }

        $dimensionSet = [];
        foreach ($dimensions as $dimension) {
            if (!is_string($dimension)
                || !in_array($dimension, self::DIMENSIONS, true)
                || isset($dimensionSet[$dimension])) {
                return null;
            }
            $dimensionSet[$dimension] = true;
        }

        foreach (array_keys($response) as $key) {
            if (!in_array($key, ['rows', 'responseAggregationType'], true)) {
                return null;
            }
        }

        if (array_key_exists('responseAggregationType', $response)
            && !in_array($response['responseAggregationType'], self::AGGREGATIONS, true)) {
            return null;
        }

        $rows = $response['rows'] ?? [];
        if (!is_array($rows)
            || !array_is_list($rows)
            || count($rows) > $rowLimit) {
            return null;
        }

        $clicks = 0.0;
        $impressions = 0.0;
        $weightedPosition = 0.0;
        $seen = [];

        foreach ($rows as $row) {
            if (!is_array($row) || array_is_list($row)) {
                return null;
            }
            foreach (array_keys($row) as $key) {
                if (!in_array($key, ['keys', 'clicks', 'impressions', 'ctr', 'position'], true)) {
                    return null;
                }
            }
            foreach (['clicks', 'impressions', 'ctr', 'position'] as $required) {
                if (!array_key_exists($required, $row)
                    || !self::validMetric($row[$required])) {
                    return null;
                }
            }

            $keys = $row['keys'] ?? [];
            if (!is_array($keys)
                || !array_is_list($keys)
                || count($keys) !== count($dimensions)) {
                return null;
            }

            foreach ($keys as $i => $key) {
                if (!is_string($key)
                    || $key === ''
                    || strlen($key) > 2048
                    || preg_match('/[\x00-\x1f\x7f]/', $key) === 1
                    || (isset($dimensions[$i])
                        && $dimensions[$i] === 'date'
                        && !self::validDateDimension($key))) {
                    return null;
                }
            }

            $serialized = json_encode($keys);
            if (!is_string($serialized)) {
                return null; // invalid UTF-8 / encoding
            }
            $keyDigest = hash('sha256', $serialized);
            if (isset($seen[$keyDigest])) {
                return null; // duplicate row dimensions are ambiguous
            }
            $seen[$keyDigest] = true;

            if ($row['ctr'] < 0 || $row['ctr'] > 1
                || $row['position'] < 0 || $row['position'] > 10000
                || $row['clicks'] < 0 || $row['impressions'] < 0) {
                return null;
            }

            $clicks += (float)$row['clicks'];
            $impressions += (float)$row['impressions'];
            $weightedPosition += (float)$row['position'] * (float)$row['impressions'];

            // Reject unrealistically large aggregate values and overflow.
            if (!is_finite($clicks) || !is_finite($impressions)
                || !is_finite($weightedPosition)
                || $clicks > 1.0e12 || $impressions > 1.0e12) {
                return null;
            }
        }

        return [
            'rowCount' => count($rows),
            'returnedClicks' => $clicks,
            'returnedImpressions' => $impressions,
            'returnedCtr' => $impressions > 0 ? $clicks / $impressions : null,
            'returnedImpressionWeightedPosition' => $impressions > 0
                ? $weightedPosition / $impressions : null,
            'possibleRowLimitTruncation' => count($rows) >= $rowLimit,
            'coverage' => 'returned_rows_only_not_complete',
            'googleOriginVerified' => false,
        ];
    }

    public static function authorizesReporting(): bool
    {
        return false;
    }

    private static function validMetric(mixed $value): bool
    {
        return (is_int($value) || is_float($value))
            && is_finite((float)$value);
    }

    private static function validDateDimension(string $date): bool
    {
        if (preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D', $date) !== 1) {
            return false;
        }

        $parts = explode('-', $date);
        return checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0]);
    }
}
