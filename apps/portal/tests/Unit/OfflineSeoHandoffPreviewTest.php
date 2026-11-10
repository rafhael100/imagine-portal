<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\SeoReview\OfflineSeoHandoffPreview;
use PHPUnit\Framework\TestCase;

final class OfflineSeoHandoffPreviewTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        putenv('APP_ENV=testing');
        putenv('PORTAL_SEO_HANDOFF_LAB=1');
    }

    protected function tearDown(): void
    {
        putenv('PORTAL_SEO_HANDOFF_LAB=0');
        parent::tearDown();
    }

    private function sample(): array
    {
        return [
            'contractVersion'=>'seo-portal-review-handoff-v1',
            'origin'=>'offline_unverified_not_for_customer_display',
            'scope'=>[
                'tenantRef'=>'imagine-demo',
                'propertyFingerprint'=>str_repeat('a',64),
                'sourceRevision'=>str_repeat('b',40),
            ],
            'releaseStatus'=>'HOLD_EXTERNAL_AUTHORIZATION',
            'customerDisplayAuthorized'=>false,
            'propertyAuthorizationVerified'=>false,
            'backendTenantGrantVerified'=>false,
            'publicationAuthorized'=>false,
            'technical'=>['status'=>'attention','pages'=>3,'errors'=>0,'warnings'=>1],
            'organic'=>null,
            'suggestedReviews'=>[['code'=>'review_page_metadata','priority'=>'medium','count'=>1]],
            'requirements'=>[
                'A trusted Portal backend must independently authenticate the user and verify the tenant grant.',
                'Google Search Console property access and consent must be proven before customer display.',
                'This offline envelope is neither a bearer token nor evidence of a verified data source.',
            ],
        ];
    }

    public function test_synthetic_cli_fixture_never_produces_operational_permission(): void
    {
        $preview=OfflineSeoHandoffPreview::fromDto($this->sample());
        self::assertInstanceOf(OfflineSeoHandoffPreview::class,$preview);
        self::assertSame('imagine-demo',$preview->tenantRef);
        self::assertSame(1,$preview->technical['warnings']);
        self::assertFalse($preview->isCustomerDisplayAuthorized());
        self::assertFalse($preview->hasVerifiedTenantGrant());
        self::assertFalse($preview->hasVerifiedGoogleProperty());
        self::assertFalse($preview->canPublish());
        self::assertFalse($preview->canDispatch());
    }

    public function test_rejects_fake_authority_and_real_tenants(): void
    {
        foreach(['customerDisplayAuthorized','propertyAuthorizationVerified',
            'backendTenantGrantVerified','publicationAuthorized'] as $flag){
            $dto=$this->sample();
            $dto[$flag]=true;
            self::assertNull(OfflineSeoHandoffPreview::fromDto($dto));
        }
        $dto=$this->sample();
        $dto['scope']['tenantRef']='real-tenant';
        self::assertNull(OfflineSeoHandoffPreview::fromDto($dto));
    }

    public function test_rejects_extra_fields_and_untrusted_actions(): void
    {
        $dto=$this->sample();
        $dto['access_token']='secret';
        self::assertNull(OfflineSeoHandoffPreview::fromDto($dto));
        $dto=$this->sample();
        $dto['suggestedReviews'][0]['code']='auto_publish_now';
        self::assertNull(OfflineSeoHandoffPreview::fromDto($dto));
        $dto=$this->sample();
        $dto['suggestedReviews'][0]['message']='<script>bad()</script>';
        self::assertNull(OfflineSeoHandoffPreview::fromDto($dto));
    }

    public function test_rejects_forged_trend_and_duplicate_actions(): void
    {
        $dto=$this->sample();
        $dto['suggestedReviews'][]=$dto['suggestedReviews'][0];
        self::assertNull(OfflineSeoHandoffPreview::fromDto($dto));
        $dto=$this->sample();
        $dto['organic']=[
            'days'=>7,
            'previous'=>['clicks'=>10,'impressions'=>100],
            'current'=>['clicks'=>5,'impressions'=>80],
            'clicksChangeFraction'=>99,
            'impressionsChangeFraction'=>-.2,
        ];
        self::assertNull(OfflineSeoHandoffPreview::fromDto($dto));
        $dto['organic']['clicksChangeFraction']=-.5;
        self::assertInstanceOf(OfflineSeoHandoffPreview::class,
            OfflineSeoHandoffPreview::fromDto($dto));
    }

    public function test_reader_is_unreachable_in_production_or_without_explicit_flag(): void
    {
        putenv('PORTAL_SEO_HANDOFF_LAB=0');
        self::assertNull(OfflineSeoHandoffPreview::fromDto($this->sample()));
        putenv('PORTAL_SEO_HANDOFF_LAB=1');
        putenv('APP_ENV=production');
        self::assertNull(OfflineSeoHandoffPreview::fromDto($this->sample()));
    }
}
