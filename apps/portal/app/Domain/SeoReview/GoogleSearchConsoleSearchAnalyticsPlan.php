<?php

declare(strict_types=1);

namespace App\Domain\SeoReview;

use DateTimeImmutable;
use DateTimeZone;

/**
 * GSC-F122: create a STRICTLY OFFLINE shape for Search Analytics requests.
 *
 * Search Analytics is POST with read-only semantics on the Google API. This
 * does not send POST, own a token, authenticate a Portal user, validate tenant
 * grants, prove property ownership, or authorize an outbound HTTP call.
 *
 * A future backend MUST recheck session/MFA, tenant/property membership and
 * current Google scope before using a separate trusted HTTPS client.
 */
final class GoogleSearchConsoleSearchAnalyticsPlan
{
    public const BASE_URL = 'https://www.googleapis.com/webmasters/v3/sites/';

    private const ALLOWED_DIMENSIONS = [
        'date', 'page', 'query', 'country', 'device',
    ];

    /**
     * @return array{method:string,url:string,body:array<string,mixed>}|null
     */
    public function buildRequestShape(
        mixed $siteUrl,
        mixed $startDate,
        mixed $endDate,
        mixed $dimensions = ['date'],
        mixed $rowLimit = 250,
    ): ?array {
        $exact = GoogleSearchConsolePropertyIdentifier::exactSiteUrl($siteUrl);

        if ($exact === null
            || !self::validDate($startDate)
            || !self::validDate($endDate)
            || $startDate > $endDate
            || !is_array($dimensions)
            || !array_is_list($dimensions)
            || count($dimensions) > 3
            || !is_int($rowLimit)
            || $rowLimit < 1
            || $rowLimit > 1000) {
            return null;
        }

        $seen = [];
        foreach ($dimensions as $dimension) {
            if (!is_string($dimension)
                || !in_array($dimension, self::ALLOWED_DIMENSIONS, true)
                || isset($seen[$dimension])) {
                return null;
            }
            $seen[$dimension] = true;
        }

        $from = DateTimeImmutable::createFromFormat('!Y-m-d', $startDate, new DateTimeZone('UTC'));
        $until = DateTimeImmutable::createFromFormat('!Y-m-d', $endDate, new DateTimeZone('UTC'));
        if ($from === false
            || $until === false
            || $from->diff($until)->days > 92) {
            return null;
        }

        // The property is encoded as ONE path parameter. Never interpolate
        // a user-controlled hostname, query string, API key, or auth header.
        return [
            'method' => 'POST',
            'url' => self::BASE_URL.rawurlencode($exact).'/searchAnalytics/query',
            'body' => [
                'startDate' => $startDate,
                'endDate' => $endDate,
                'dimensions' => $dimensions,
                'type' => 'web',
                'aggregationType' => 'auto',
                'rowLimit' => $rowLimit,
                'dataState' => 'final',
            ],
        ];
    }

    public static function authorizesOutboundRequest(): bool
    {
        return false;
    }

    private static function validDate(mixed $value): bool
    {
        if (!is_string($value)
            || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D', $value) !== 1) {
            return false;
        }

        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $value,
            new DateTimeZone('UTC'),
        );

        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
