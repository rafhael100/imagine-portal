<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\SeoReview\PortalSeoSyntheticFunnelAttributionReview as Funnel;
use PHPUnit\Framework\TestCase;

final class PortalSeoSyntheticFunnelAttributionReviewTest extends TestCase
{
    private array $prior=[];

    protected function setUp():void
    {
        parent::setUp();
        foreach([
            'APP_ENV'=>'testing',
            'CI'=>'true',
            'PORTAL_SEO_F117_LAB'=>'1'
        ] as $key=>$value){
            $this->prior[$key]=getenv($key);
            putenv($key.'='.$value);
        }
    }

    protected function tearDown():void
    {
        foreach($this->prior as $key=>$value){
            if($value===false)putenv($key);
            else putenv($key.'='.$value);
        }
        parent::tearDown();
    }

    private function input():array
    {
        $period=['startDate'=>'2026-10-01','endDate'=>'2026-10-31'];
        return [
            'tenantRef'=>'demo-tenant',
            'period'=>$period,
            'analytics'=>[
                'source'=>'synthetic_analytics_ci',
                'tenantRef'=>'demo-tenant','period'=>$period,
                'sessions'=>1250,'providerVerified'=>false
            ],
            'crm'=>[
                'source'=>'synthetic_crm_ci','tenantRef'=>'demo-tenant',
                'period'=>$period,'attributedLeads'=>75,
                'attributedCustomers'=>15,'providerVerified'=>false,
                'attributionVerified'=>false
            ]
        ];
    }

    public function test_three_pillars_have_bounded_synthetic_rates_without_authority():void
    {
        $result=(new Funnel())->compare($this->input());
        self::assertNotNull($result);
        self::assertSame('VISITAS_LEADS_CLIENTES',$result['methodology']);
        self::assertSame(
            ['VISITAS','LEADS','CLIENTES'],
            array_column($result['stages'],'name')
        );
        self::assertSame([1250,75,15],array_column($result['stages'],'value'));
        self::assertSame([
            'visitsToLeads'=>6.0,
            'leadsToCustomers'=>20.0,
            'visitsToCustomers'=>1.2
        ],$result['conversionRatesPercent']);
        foreach([
            'realAttributionVerified','analyticsOriginVerified',
            'crmOriginVerified','portalSessionVerified',
            'portalTenantGrantVerified','customerDisplayAuthorized',
            'productionReadAuthorized','publicationAuthorized',
            'networkPerformed'
        ] as $key)self::assertFalse($result[$key],$key);
        self::assertSame(
            'HOLD_AUTHENTICATED_ANALYTICS_CRM_ATTRIBUTION',
            $result['releaseStatus']
        );
        self::assertStringNotContainsString(
            'demo-tenant',json_encode($result,JSON_THROW_ON_ERROR)
        );
        self::assertFalse(Funnel::authorizesCustomerDisplay());
        self::assertFalse(Funnel::authorizesRealAttribution());
        self::assertFalse(Funnel::authorizesGoogleHttp());
    }

    public function test_zero_denominator_is_not_an_invented_conversion_rate():void
    {
        $input=$this->input();
        $input['analytics']['sessions']=0;
        $input['crm']['attributedLeads']=0;
        $input['crm']['attributedCustomers']=0;
        $report=(new Funnel())->compare($input);
        self::assertNotNull($report);
        self::assertSame([0,0,0],array_column($report['stages'],'value'));
        self::assertSame([
            'visitsToLeads'=>null,
            'leadsToCustomers'=>null,
            'visitsToCustomers'=>null
        ],$report['conversionRatesPercent']);
    }

    public function test_forged_provider_grant_or_extra_fields_are_denied():void
    {
        $changes=[
            function(array &$x):void{$x['oauthVerified']=true;},
            function(array &$x):void{$x['analytics']['providerVerified']=true;},
            function(array &$x):void{$x['crm']['attributionVerified']=true;},
            function(array &$x):void{$x['crm']['providerVerified']=true;},
            function(array &$x):void{$x['analytics']['access_token']='SECRET';},
            function(array &$x):void{$x['crm']['email']='p@example.com';},
            function(array &$x):void{$x['crm']['source']='production_crm';},
            function(array &$x):void{$x['analytics']['source']='google_analytics';},
            function(array &$x):void{$x['crm']['tenantRef']='other-tenant';},
            function(array &$x):void{$x['analytics']['tenantRef']='other-tenant';},
            function(array &$x):void{$x['crm']['period']['endDate']='2026-10-30';},
            function(array &$x):void{$x['analytics']['period']['endDate']='2026-10-30';}
        ];
        foreach($changes as $change){
            $sample=$this->input();
            $change($sample);
            self::assertNull((new Funnel())->compare($sample));
        }
    }

    public function test_invalid_rates_and_periods_fail_closed():void
    {
        $changes=[
            function(array &$x):void{$x['crm']['attributedCustomers']=76;},
            function(array &$x):void{$x['crm']['attributedLeads']=1251;},
            function(array &$x):void{$x['analytics']['sessions']=-1;},
            function(array &$x):void{$x['crm']['attributedLeads']=0.5;},
            function(array &$x):void{$x['crm']['attributedCustomers']=null;},
            function(array &$x):void{$x['analytics']['sessions']=1000000001;},
            function(array &$x):void{$x['period']['startDate']='2026-02-30';},
            function(array &$x):void{$x['period']['endDate']='2025-10-31';},
            function(array &$x):void{$x['period']['endDate']='2027-10-31';},
            function(array &$x):void{$x['period']['startDate']='2026/10/01';},
            function(array &$x):void{$x['tenantRef']='INVALID-ID';}
        ];
        foreach($changes as $change){
            $sample=$this->input();
            $change($sample);
            self::assertNull((new Funnel())->compare($sample));
        }
    }

    public function test_even_synthetic_preview_is_disabled_outside_lab():void
    {
        $instance=new Funnel();
        putenv('APP_ENV=production');
        self::assertNull($instance->compare($this->input()));
        putenv('APP_ENV=testing');
        putenv('PORTAL_SEO_F117_LAB=0');
        self::assertNull($instance->compare($this->input()));
        putenv('PORTAL_SEO_F117_LAB=1');
        putenv('CI=false');
        self::assertNull($instance->compare($this->input()));
    }
}
