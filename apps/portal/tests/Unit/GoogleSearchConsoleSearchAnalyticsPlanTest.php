<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\SeoReview\GoogleSearchConsoleSearchAnalyticsPlan;
use PHPUnit\Framework\TestCase;

final class GoogleSearchConsoleSearchAnalyticsPlanTest extends TestCase
{
    private const DOMAIN = 'sc-domain:imaginecomunicacao.com.br';
    private const PREFIX = 'https://www.imaginecomunicacao.com.br/';

    public function test_domain_property_request_plan_is_bounded_and_has_no_credentials(): void
    {
        $plan = (new GoogleSearchConsoleSearchAnalyticsPlan())->buildRequestShape(
            self::DOMAIN, '2026-10-01', '2026-10-28', ['date', 'page'], 250,
        );

        self::assertSame([
            'method' => 'POST',
            'url' => 'https://www.googleapis.com/webmasters/v3/sites/'
                .'sc-domain%3Aimaginecomunicacao.com.br/searchAnalytics/query',
            'body' => [
                'startDate' => '2026-10-01',
                'endDate' => '2026-10-28',
                'dimensions' => ['date', 'page'],
                'type' => 'web',
                'aggregationType' => 'auto',
                'rowLimit' => 250,
                'dataState' => 'final',
            ],
        ], $plan);
        self::assertFalse(GoogleSearchConsoleSearchAnalyticsPlan::authorizesOutboundRequest());

        $serialized = json_encode($plan, JSON_THROW_ON_ERROR);
        foreach (['access_token', 'refresh_token', 'client_secret', 'apiKey',
            'Authorization', 'Bearer ', 'searchType', 'dimensionFilterGroups'] as $secret) {
            self::assertStringNotContainsString($secret, $serialized);
        }
    }

    public function test_url_prefix_is_encoded_as_one_path_parameter_without_host_control(): void
    {
        $plan = (new GoogleSearchConsoleSearchAnalyticsPlan())->buildRequestShape(
            self::PREFIX, '2026-11-01', '2026-11-02', ['query', 'device'], 50,
        );

        self::assertIsArray($plan);
        self::assertSame(
            'https://www.googleapis.com/webmasters/v3/sites/'
                .'https%3A%2F%2Fwww.imaginecomunicacao.com.br%2F/searchAnalytics/query',
            $plan['url'],
        );
        self::assertSame('www.googleapis.com', parse_url($plan['url'], PHP_URL_HOST));
        self::assertSame('web', $plan['body']['type']);
        self::assertSame('final', $plan['body']['dataState']);
    }

    public function test_rejects_arbitrary_hosts_invalid_dates_duplicates_and_sensitive_dimensions(): void
    {
        $planner = new GoogleSearchConsoleSearchAnalyticsPlan();

        $invalid = [
            ['https://evil.invalid/?url=https://example.com/', '2026-11-01', '2026-11-02', ['date'], 250],
            ['sc-domain:EXAMPLE.com', '2026-11-01', '2026-11-02', ['date'], 250],
            [self::DOMAIN, '2026-13-01', '2026-11-02', ['date'], 250],
            [self::DOMAIN, '2026-02-29', '2026-03-01', ['date'], 250],
            [self::DOMAIN, '2026-11-03', '2026-11-02', ['date'], 250],
            [self::DOMAIN, '2026-01-01', '2026-11-02', ['date'], 250],
            [self::DOMAIN, '2026-11-01 00:00', '2026-11-02', ['date'], 250],
            [self::DOMAIN, '2026-11-01', '2026-11-02', ['date', 'date'], 250],
            [self::DOMAIN, '2026-11-01', '2026-11-02', ['date', 'page', 'query', 'country'], 250],
            [self::DOMAIN, '2026-11-01', '2026-11-02', ['searchAppearance'], 250],
            [self::DOMAIN, '2026-11-01', '2026-11-02', ['hour'], 250],
            [self::DOMAIN, '2026-11-01', '2026-11-02', ['date' => 'page'], 250],
            [self::DOMAIN, '2026-11-01', '2026-11-02', ['date', 123], 250],
            [self::DOMAIN, '2026-11-01', '2026-11-02', ['date'], 0],
            [self::DOMAIN, '2026-11-01', '2026-11-02', ['date'], 1001],
            [self::DOMAIN, '2026-11-01', '2026-11-02', ['date'], '250'],
            [self::DOMAIN, null, '2026-11-02', ['date'], 250],
            [self::DOMAIN, '2026-11-01', null, ['date'], 250],
            [self::DOMAIN, '2026-11-01', '2026-11-02', 'date', 250],
        ];

        foreach ($invalid as [$site, $start, $end, $dimensions, $limit]) {
            self::assertNull(
                $planner->buildRequestShape($site, $start, $end, $dimensions, $limit),
                json_encode([$site, $start, $end, $dimensions, $limit], JSON_THROW_ON_ERROR),
            );
        }
    }

    public function test_empty_dimensions_gives_summary_only_and_cannot_execute_network(): void
    {
        $planner = new GoogleSearchConsoleSearchAnalyticsPlan();
        $plan = $planner->buildRequestShape(
            'https://example.com/blog/', '2026-11-10', '2026-11-10', [], 1,
        );
        self::assertIsArray($plan);
        self::assertSame([], $plan['body']['dimensions']);
        self::assertSame(1, $plan['body']['rowLimit']);
        self::assertFalse(GoogleSearchConsoleSearchAnalyticsPlan::authorizesOutboundRequest());
    }
}
