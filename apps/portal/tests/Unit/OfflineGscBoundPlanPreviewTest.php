<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\SeoReview\OfflineGscBoundPlanPreview;
use PHPUnit\Framework\TestCase;

final class OfflineGscBoundPlanPreviewTest extends TestCase
{
    private string|false $previousAppEnv;
    private string|false $previousFlag;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousAppEnv=getenv('APP_ENV');
        $this->previousFlag=getenv('PORTAL_SEO_GSC_LAB');
        putenv('APP_ENV=testing');
        putenv('PORTAL_SEO_GSC_LAB=1');
    }

    protected function tearDown(): void
    {
        putenv($this->previousAppEnv===false ? 'APP_ENV' : 'APP_ENV='.$this->previousAppEnv);
        putenv($this->previousFlag===false ? 'PORTAL_SEO_GSC_LAB' :
            'PORTAL_SEO_GSC_LAB='.$this->previousFlag);
        parent::tearDown();
    }

    private function sample(): array
    {
        return [
            'contractVersion'=>'seo-gsc-offline-bound-plan-v1',
            'tenantRef'=>'imagine-demo',
            'propertyFingerprint'=>str_repeat('a',64),
            'exactPropertyListed'=>true,
            'declaredReadPermissionEligible'=>true,
            'scopeIntegrityCheckedOffline'=>true,
            'oauthVerified'=>false,
            'tenantGrantVerified'=>false,
            'productionReadAuthorized'=>false,
            'networkPerformed'=>false,
            'authorization'=>'HOLD_EXTERNAL_BACKEND_VERIFICATION',
        ];
    }

    public function test_synthetic_matching_property_does_not_authorize_google_or_customers(): void
    {
        $preview=OfflineGscBoundPlanPreview::fromDto($this->sample());
        self::assertInstanceOf(OfflineGscBoundPlanPreview::class,$preview);
        self::assertSame('imagine-demo',$preview->tenantRef);
        self::assertTrue($preview->exactPropertyListed);
        self::assertTrue($preview->declaredReadPermissionEligible);
        self::assertFalse($preview->canReadGoogle());
        self::assertFalse($preview->hasVerifiedTenantGrant());
        self::assertFalse($preview->canDisplayToCustomers());
        self::assertFalse($preview->canPublish());
        self::assertFalse($preview->canDispatch());
    }

    public function test_missing_property_stays_in_hold(): void
    {
        $dto=$this->sample();
        $dto['exactPropertyListed']=false;
        $dto['declaredReadPermissionEligible']=false;
        $preview=OfflineGscBoundPlanPreview::fromDto($dto);
        self::assertInstanceOf(OfflineGscBoundPlanPreview::class,$preview);
        self::assertFalse($preview->canReadGoogle());
    }

    public function test_rejects_forged_google_or_portal_permissions(): void
    {
        foreach(['oauthVerified','tenantGrantVerified','productionReadAuthorized',
            'networkPerformed'] as $flag){
            $dto=$this->sample();
            $dto[$flag]=true;
            self::assertNull(OfflineGscBoundPlanPreview::fromDto($dto),$flag);
        }
        $dto=$this->sample();
        $dto['authorization']='APPROVED';
        self::assertNull(OfflineGscBoundPlanPreview::fromDto($dto));
        $dto=$this->sample();
        $dto['scopeIntegrityCheckedOffline']=false;
        self::assertNull(OfflineGscBoundPlanPreview::fromDto($dto));
    }

    public function test_rejects_real_tenants_fingerprints_unknown_fields_and_inconsistent_access(): void
    {
        foreach([
            ['tenantRef'=>'real-client'],
            ['propertyFingerprint'=>str_repeat('b',64)],
            ['access_token'=>'secret'],
            ['declaredReadPermissionEligible'=>'yes'],
            ['exactPropertyListed'=>false],
        ] as $change){
            $dto=array_replace($this->sample(),$change);
            self::assertNull(OfflineGscBoundPlanPreview::fromDto($dto));
        }
    }

    public function test_unavailable_without_cli_testing_and_separate_lab_flag(): void
    {
        putenv('PORTAL_SEO_GSC_LAB=0');
        self::assertNull(OfflineGscBoundPlanPreview::fromDto($this->sample()));
        putenv('PORTAL_SEO_GSC_LAB=1');
        putenv('APP_ENV=production');
        self::assertNull(OfflineGscBoundPlanPreview::fromDto($this->sample()));
    }
}
