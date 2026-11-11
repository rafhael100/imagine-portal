<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\SeoReview\OfflineGscWorkflowPreview;
use PHPUnit\Framework\TestCase;

final class OfflineGscWorkflowPreviewTest extends TestCase
{
    private string|false $oldEnv;
    private string|false $oldFlag;

    protected function setUp(): void
    {
        parent::setUp();
        $this->oldEnv=getenv('APP_ENV');
        $this->oldFlag=getenv('PORTAL_GSC_WORKFLOW_LAB');
        putenv('APP_ENV=testing');
        putenv('PORTAL_GSC_WORKFLOW_LAB=1');
    }

    protected function tearDown(): void
    {
        putenv($this->oldEnv===false?'APP_ENV':'APP_ENV='.$this->oldEnv);
        putenv($this->oldFlag===false?'PORTAL_GSC_WORKFLOW_LAB':'PORTAL_GSC_WORKFLOW_LAB='.$this->oldFlag);
        parent::tearDown();
    }

    private function fixture(): array
    {
        return [
            'contractVersion'=>'seo-gsc-offline-workflow-v1',
            'tenantRef'=>'imagine-demo',
            'propertyFingerprint'=>hash('sha256','sc-domain:example.com'),
            'sourceType'=>'operator_supplied_not_verified',
            'status'=>'OFFLINE_REVIEW_ONLY',
            'pagination'=>[
                'pagesReceived'=>1,'rowsReceived'=>2,
                'mayHaveMore'=>false,'sourceMayBeTruncated'=>true,
            ],
            'summary'=>[
                'inputRows'=>2,'redactedRows'=>1,'analyzedRows'=>1,
                'clicks'=>2,'impressions'=>10,
            ],
            'oauthVerified'=>false,'backendTenantGrantVerified'=>false,
            'propertyConsentVerified'=>false,'customerDisplayAuthorized'=>false,
            'productionReadAuthorized'=>false,'networkPerformed'=>false,
            'releaseStatus'=>'HOLD_EXTERNAL_BACKEND_VERIFICATION',
        ];
    }

    public function test_accepts_only_a_synthetic_hold_envelope_without_operational_permissions(): void
    {
        $preview=OfflineGscWorkflowPreview::fromDto($this->fixture());
        self::assertInstanceOf(OfflineGscWorkflowPreview::class,$preview);
        self::assertSame('OFFLINE_REVIEW_ONLY',$preview->status);
        self::assertSame(1,$preview->summary['redactedRows']);
        self::assertFalse($preview->isCustomerDisplayAuthorized());
        self::assertFalse($preview->canQueryGoogle());
        self::assertFalse($preview->hasVerifiedTenantGrant());
        self::assertFalse($preview->canStoreTokens());
        self::assertFalse($preview->canPublish());
    }

    public function test_denied_property_may_not_include_any_report_metrics(): void
    {
        $dto=$this->fixture();
        $dto['status']='DECLARED_PROPERTY_NOT_ELIGIBLE';
        $dto['summary']=null;
        $dto['pagination']=null;
        self::assertInstanceOf(OfflineGscWorkflowPreview::class,
            OfflineGscWorkflowPreview::fromDto($dto));
        $dto['summary']=['inputRows'=>0];
        self::assertNull(OfflineGscWorkflowPreview::fromDto($dto));
    }

    public function test_rejects_forged_authentication_tenant_and_raw_data(): void
    {
        $base=$this->fixture();
        foreach ([
            ['oauthVerified'=>true],
            ['backendTenantGrantVerified'=>true],
            ['customerDisplayAuthorized'=>true],
            ['networkPerformed'=>true],
            ['tenantRef'=>'real-tenant'],
            ['propertyFingerprint'=>str_repeat('a',64)],
            ['releaseStatus'=>'READY'],
            ['access_token'=>'private'],
            ['rawQuery'=>'person@example.com'],
            ['status'=>'GOOGLE_CONNECTED'],
        ] as $change) {
            self::assertNull(OfflineGscWorkflowPreview::fromDto([...$base,...$change]));
        }
    }

    public function test_rejects_malformed_metrics_and_pagination(): void
    {
        $base=$this->fixture();
        foreach ([
            ['clicks'=>11],['impressions'=>-1],['redactedRows'=>2],
            ['analyzedRows'=>3],['inputRows'=>'2'],['token'=>'private'],
        ] as $invalid) {
            $dto=$base;
            $dto['summary']=[...$base['summary'],...$invalid];
            self::assertNull(OfflineGscWorkflowPreview::fromDto($dto));
        }
        foreach ([
            ['rowsReceived'=>3],['pagesReceived'=>0],
            ['sourceMayBeTruncated'=>false],['oauthVerified'=>true],
        ] as $invalid) {
            $dto=$base;
            $dto['pagination']=[...$base['pagination'],...$invalid];
            self::assertNull(OfflineGscWorkflowPreview::fromDto($dto));
        }
    }

    public function test_reader_cannot_run_in_production_or_without_testing_flag(): void
    {
        putenv('PORTAL_GSC_WORKFLOW_LAB=0');
        self::assertNull(OfflineGscWorkflowPreview::fromDto($this->fixture()));
        putenv('PORTAL_GSC_WORKFLOW_LAB=1');
        putenv('APP_ENV=production');
        self::assertNull(OfflineGscWorkflowPreview::fromDto($this->fixture()));
    }
}
