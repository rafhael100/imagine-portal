<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\SeoReview\GoogleSearchConsoleQueryPagePaginationPlan as Planner;
use PHPUnit\Framework\TestCase;

final class GoogleSearchConsoleQueryPagePaginationPlanTest extends TestCase
{
    private const DOMAIN='sc-domain:imaginecomunicacao.com.br';
    private const PREFIX='https://www.imaginecomunicacao.com.br/';

    public function test_generates_bounded_by_page_read_only_plan_without_credentials(): void
    {
        $request=(new Planner())->buildRequestShape(
            self::DOMAIN,'2026-10-01','2026-10-28',1000,1000
        );
        self::assertSame('POST',$request['method']);
        self::assertSame(
            'https://www.googleapis.com/webmasters/v3/sites/'.
            'sc-domain%3Aimaginecomunicacao.com.br/searchAnalytics/query',
            $request['url']
        );
        self::assertSame([
            'startDate'=>'2026-10-01',
            'endDate'=>'2026-10-28',
            'dimensions'=>['query','page'],
            'type'=>'web',
            'aggregationType'=>'byPage',
            'rowLimit'=>1000,
            'dataState'=>'final',
            'startRow'=>1000,
        ],$request['body']);
        $json=json_encode($request,JSON_THROW_ON_ERROR);
        foreach(['access_token','Authorization','Bearer ',
            'client_secret','refresh_token','apiKey','prompt','redirect_uri'] as $secret)
            self::assertStringNotContainsString($secret,$json);
        self::assertFalse(Planner::authorizesOutboundRequest());
        self::assertFalse(Planner::authorizesGoogleAccess());
        self::assertFalse(Planner::authorizesPublication());
    }

    public function test_url_prefix_remains_exact_and_encoded_as_a_single_path_component(): void
    {
        $request=(new Planner())->buildRequestShape(
            self::PREFIX,'2026-11-01','2026-11-02',250,750
        );
        self::assertIsArray($request);
        self::assertSame(
            'https://www.googleapis.com/webmasters/v3/sites/'.
            'https%3A%2F%2Fwww.imaginecomunicacao.com.br%2F/searchAnalytics/query',
            $request['url']
        );
        self::assertSame('www.googleapis.com',parse_url($request['url'],PHP_URL_HOST));
        self::assertSame(750,$request['body']['startRow']);
    }

    public function test_enforces_at_most_four_contiguous_pages_without_overflow(): void
    {
        $planner=new Planner();
        for($index=0;$index<4;$index++){
            self::assertNotNull($planner->buildRequestShape(
                self::DOMAIN,'2026-10-01','2026-10-07',1000,$index*1000
            ));
        }
        foreach([
            [1000,4000],
            [250,1000],
            [250,1001],
            [1,4],
            [1001,0],
            [0,0],
            [1000,-1000],
            [1000,1],
            [500,100000],
            ['1000',1000],
            [1000,'1000'],
            [1000,1.0],
        ] as [$limit,$offset]){
            self::assertNull($planner->buildRequestShape(
                self::DOMAIN,'2026-10-01','2026-10-07',$limit,$offset
            ),json_encode([$limit,$offset],JSON_THROW_ON_ERROR));
        }
    }

    public function test_rejects_unsafe_property_and_unfinalized_dates(): void
    {
        $planner=new Planner();
        foreach([
            ['https://example.com?token=secret','2026-11-01','2026-11-02'],
            ['sc-domain:EXAMPLE.com','2026-11-01','2026-11-02'],
            [self::PREFIX,'2026-02-30','2026-11-02'],
            [self::PREFIX,'2026-11-03','2026-11-02'],
            [self::PREFIX,'2026-01-01','2026-11-11'],
            [null,'2026-11-01','2026-11-02'],
        ] as [$site,$start,$end]){
            self::assertNull($planner->buildRequestShape(
                $site,$start,$end,1000,0
            ));
        }
    }
}
