<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\SeoReview\PortalSeoStaticBuildReadinessLab as Review;
use PHPUnit\Framework\TestCase;

final class PortalSeoStaticBuildReadinessLabTest extends TestCase
{
    private array $original=[];

    protected function setUp():void
    {
        parent::setUp();
        foreach(['APP_ENV'=>'testing','CI'=>'true',
            'PORTAL_SEO_F118_LAB'=>'1'] as $key=>$value){
            $this->original[$key]=getenv($key);
            putenv($key.'='.$value);
        }
    }

    protected function tearDown():void
    {
        foreach($this->original as $key=>$v) {
            if($v===false)putenv($key);
            else putenv($key.'='.$v);
        }
        parent::tearDown();
    }

    private function dto():array
    {
        return [
            'contractVersion'=>'seo-municipal-static-build-reconciliation-offline-v1',
            'sourceType'=>'operator_supplied_inventory_and_local_build_unverified',
            'reviewStatus'=>'blocked',
            'summary'=>[
                'inventoryEntries'=>2,'inventoryConflicts'=>0,
                'auditedBuildPages'=>3,'expectedRoutesFound'=>1,
                'expectedRoutesMissing'=>1,'expectedRoutesWrongLocale'=>1,
                'omittedMissingRouteIndices'=>0,
                'omittedWrongLocaleIndices'=>0,
                'otherSitePages'=>2,'technicalErrors'=>1,
                'technicalWarnings'=>0,'privateEditorialPreviewLeaks'=>1
            ],
            'missingInventoryIndices'=>[1],
            'wrongLocaleInventoryIndices'=>[0],
            'technicalErrorTypes'=>[[
                'code'=>'private_editorial_preview_in_public_build',
                'count'=>1
            ]],
            'technicalWarningTypes'=>[],
            'auditedRealLocalFiles'=>true,
            'confirmedPublishedSite'=>false,
            'authenticatedIbgeReference'=>false,
            'realContentDigestVerified'=>false,
            'inventoryCompletenessVerified'=>false,
            'editorialApprovalVerified'=>false,
            'productionCanonicalVerified'=>false,
            'customerDisplayAuthorized'=>false,
            'deploymentAuthorized'=>false,
            'indexingAuthorized'=>false,
            'networkPerformed'=>false,
            'releaseStatus'=>'HOLD_INDEPENDENT_EDITORIAL_SOURCE_AND_STAGING_GATES',
            'limitations'=>[
                'Unverified https://example.com/private-token/SECRET',
                'Municipality code 2304400 and city Fortaleza',
                'A private URL should not be shown.',
                'This local inspection never authorizes any publication.'
            ]
        ];
    }

    public function test_blocked_report_is_reduced_to_safe_aggregate_and_never_authorizes():void
    {
        $result=(new Review())->review($this->dto());
        self::assertNotNull($result);
        self::assertSame('REVIEW_BLOCKED',$result['reviewStatus']);
        self::assertSame(1,$result['counts']['municipalPagesMissing']);
        self::assertSame(1,$result['counts']['wrongLanguagePages']);
        self::assertSame(1,$result['counts']['privateDraftLeaks']);
        self::assertSame(
            ['private_editorial_preview_in_public_build'],
            $result['technicalIssueCategories']
        );
        foreach([
            'realSiteVerified','officialIbgeVerified','portalIdentityVerified',
            'tenantAccessVerified','realAnalyticsVerified',
            'customerDisplayAuthorized','productionReadAuthorized',
            'publicationAuthorized','networkPerformed'
        ] as $field)self::assertFalse($result[$field],$field);
        $json=json_encode($result,JSON_THROW_ON_ERROR);
        foreach(['example.com','SECRET','2304400','Fortaleza','private-token',
            'missingInventoryIndices','wrongLocaleInventoryIndices']
            as $private)self::assertStringNotContainsString($private,$json);
        self::assertFalse(Review::authorizesDisplay());
        self::assertFalse(Review::authorizesDeployment());
    }

    public function test_clean_offline_result_still_only_requires_human_review():void
    {
        $dto=$this->dto();
        $dto['reviewStatus']='human_independent_review_required';
        $dto['summary']['expectedRoutesFound']=2;
        $dto['summary']['expectedRoutesMissing']=0;
        $dto['summary']['expectedRoutesWrongLocale']=0;
        $dto['summary']['technicalErrors']=0;
        $dto['summary']['privateEditorialPreviewLeaks']=0;
        $dto['missingInventoryIndices']=[];
        $dto['wrongLocaleInventoryIndices']=[];
        $dto['technicalErrorTypes']=[];
        $result=(new Review())->review($dto);
        self::assertNotNull($result);
        self::assertSame('HUMAN_REVIEW_REQUIRED',$result['reviewStatus']);
        self::assertFalse($result['publicationAuthorized']);
        self::assertFalse($result['realSiteVerified']);
    }

    public function test_forged_authorizations_or_unexpected_data_are_rejected():void
    {
        $mutations=[
            static function(array &$x):void{$x['customerDisplayAuthorized']=true;},
            static function(array &$x):void{$x['deploymentAuthorized']=true;},
            static function(array &$x):void{$x['confirmedPublishedSite']=true;},
            static function(array &$x):void{$x['authenticatedIbgeReference']=true;},
            static function(array &$x):void{$x['oauthVerified']=true;},
            static function(array &$x):void{$x['tenantRef']='other-client';},
            static function(array &$x):void{$x['summary']['unexpectedKey']=1;},
            static function(array &$x):void{$x['summary']['inventoryEntries']=1;},
            static function(array &$x):void{$x['summary']['expectedRoutesWrongLocale']=2;},
            static function(array &$x):void{$x['summary']['privateEditorialPreviewLeaks']=2;},
            static function(array &$x):void{$x['reviewStatus']='human_independent_review_required';},
            static function(array &$x):void{$x['missingInventoryIndices']=[0];},
            static function(array &$x):void{$x['wrongLocaleInventoryIndices']=[1];},
            static function(array &$x):void{$x['technicalErrorTypes'][0]['code']='url=https://bad.example';},
            static function(array &$x):void{$x['technicalErrorTypes'][0]['count']=4;},
            static function(array &$x):void{$x['limitations'][]='unexpected';},
            static function(array &$x):void{$x['sourceType']='verified_production';},
        ];
        foreach($mutations as $mutation){
            $dto=$this->dto();
            $mutation($dto);
            self::assertNull((new Review())->review($dto));
        }
    }

    public function test_unverified_environment_stays_closed():void
    {
        $lab=new Review();
        putenv('APP_ENV=production');
        self::assertNull($lab->review($this->dto()));
        putenv('APP_ENV=testing');
        putenv('PORTAL_SEO_F118_LAB=0');
        self::assertNull($lab->review($this->dto()));
        putenv('PORTAL_SEO_F118_LAB=1');
        putenv('CI=false');
        self::assertNull($lab->review($this->dto()));
    }
}
