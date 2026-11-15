<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Authorization\Permission;
use App\Domain\Identity\PortalUserId;
use App\Domain\Organizations\MembershipStatus;
use App\Domain\Organizations\OrganizationId;
use App\Domain\Organizations\OrganizationMembership;
use App\Domain\Organizations\OrganizationRole;
use App\Domain\PortalAccess\Authority\PortalSessionAuthority;
use App\Domain\PortalAccess\Authority\PortalSessionSnapshot;
use App\Domain\PortalAccess\Authority\PortalMembershipAuthority;
use App\Domain\PortalAccess\Authority\PortalResourceAuthority;
use App\Domain\SeoReview\GoogleSearchConsoleOAuthPreflight;
use App\Domain\SeoReview\RecordedGscGrantReviewSource;
use App\Domain\SeoReview\SeoFunnelTenantPropertyBoundary;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** F107: synthetic source adapters, NEVER attached to an HTTP route. */
final class SeoFunnelTenantPropertyBoundaryTest extends TestCase
{
    private const NOW = 1800000000;

    protected function setUp(): void
    {
        parent::setUp();
        putenv('APP_ENV=testing');
        putenv('PORTAL_SEO_F106_LAB=1');
        putenv('PORTAL_SEO_F107_LAB=1');
    }

    protected function tearDown(): void
    {
        putenv('PORTAL_SEO_F107_LAB');
        putenv('PORTAL_SEO_F106_LAB');
        parent::tearDown();
    }

    /** Minimal synthetic F106 DTO with the exact original closed contract. */
    private function dto(): array
    {
        return [
            'contractVersion'=>'seo-visitas-leads-clientes-preview-offline-v1',
            'methodology'=>'VISITAS_LEADS_CLIENTES',
            'sourceType'=>'operator_supplied_gsc_snapshots_not_provider_verified',
            'scope'=>[
                'tenantRef'=>'demo-tenant',
                'propertyFingerprint'=>hash('sha256','sc-domain:example.com')
            ],
            'period'=>[
                'startDate'=>'2026-10-03','endDate'=>'2026-10-04',
                'requestedDays'=>2,'returnedDays'=>2,'unreturnedDays'=>0
            ],
            'organicSearchDiscovery'=>[
                'source'=>'manual_unverified_gsc_date_rows',
                'clicks'=>3,'impressions'=>30,'ctr'=>0.1,
                'weightedAveragePosition'=>8,
                'clicksChangePercent'=>0,'impressionsChangePercent'=>0,
                'dataCaveat'=>'SUBMITTED_DATES_ONLY_NOT_PROVIDER_VERIFIED'
            ],
            'organicSearchDiscoveryStatus'=>'SUBMITTED_DATES_ONLY_ORIGIN_UNVERIFIED',
            'stages'=>[
                [
                    'name'=>'VISITAS','metric'=>'analytics_sessions','value'=>null,
                    'availability'=>'NOT_AVAILABLE',
                    'requiredSource'=>'authorized_web_analytics',
                    'reason'=>'Search Console clicks do not establish visits or sessions.'
                ],[
                    'name'=>'LEADS','metric'=>'attributed_crm_leads','value'=>null,
                    'availability'=>'NOT_AVAILABLE',
                    'requiredSource'=>'authorized_crm_lead_events',
                    'reason'=>'A search click does not establish a recorded lead.'
                ],[
                    'name'=>'CLIENTES','metric'=>'attributed_crm_customers','value'=>null,
                    'availability'=>'NOT_AVAILABLE',
                    'requiredSource'=>'authorized_crm_customer_events',
                    'reason'=>'A lead or search click does not establish a won customer.'
                ],
            ],
            'conversionRates'=>[
                'visitsToLeads'=>null,'leadsToCustomers'=>null,'visitsToCustomers'=>null
            ],
            'sourceCompletenessNotProven'=>true,
            'requiresIndependentTenantPropertyGrant'=>true,
            'requiresVerifiedWebAnalytics'=>true,
            'requiresAuthorizedCrmAttribution'=>true,
            'oauthVerifiedForImagine'=>false,
            'portalTenantGrantVerified'=>false,
            'customerDisplayAuthorized'=>false,
            'productionReadAuthorized'=>false,
            'publicationAuthorized'=>false,
            'networkPerformed'=>false,
            'reviewSignals'=>[],
            'releaseStatus'=>'HOLD_IMAGINE_BACKEND_AUTHORIZATION',
            'caveats'=>['source not verified','visits not from GSC',
                'CRM data unavailable','no production authorization']
        ];
    }

    private function grant(): array
    {
        return [
            'contractVersion'=>'portal-gsc-property-grant-record-review-v1',
            'tenantRef'=>'demo-tenant',
            'actorRef'=>'portal-user-a',
            'propertyFingerprint'=>hash('sha256','sc-domain:example.com'),
            'recordedGrantFresh'=>true,
            'recordedScope'=>GoogleSearchConsoleOAuthPreflight::READONLY_SCOPE,
            'verifiedAt'=>self::NOW-60,
            'expiresAt'=>self::NOW+600,
            'googleApiCheckedNow'=>false,
            'sessionMfaVerified'=>false,
            'tenantMembershipVerified'=>false,
            'propertyAuthorizationVerified'=>false,
            'customerDisplayAuthorized'=>false,
            'publicationAuthorized'=>false,
            'networkPerformed'=>false,
            'releaseStatus'=>'HOLD_TRUSTED_AUTH_AND_GOOGLE_PROOF'
        ];
    }

    private function fixture(
        ?PortalSessionSnapshot $session = null,
        ?OrganizationMembership $membership = null,
        ?OrganizationId $owner = null,
        ?array $grant = null,
        bool $noGrant = false,
        bool $error = false,
    ): SeoFunnelTenantPropertyBoundary {
        $actor=new PortalUserId('portal-user-a');
        $org=new OrganizationId('demo-tenant');
        $session ??= new PortalSessionSnapshot(
            $actor,true,true,self::NOW-300,self::NOW+600,2,2
        );
        $membership ??= new OrganizationMembership(
            $actor,$org,OrganizationRole::Member,
            MembershipStatus::Active,[Permission::ViewSeo]
        );
        $owner ??= $org;
        $sessions=new class($session) implements PortalSessionAuthority {
            public function __construct(private PortalSessionSnapshot $session) {}
            public function find(string $opaqueReference): ?PortalSessionSnapshot
            {
                return $opaqueReference==='lab-opaque-session'?$this->session:null;
            }
        };
        $memberships=new class($membership) implements PortalMembershipAuthority {
            public function __construct(private OrganizationMembership $membership) {}
            public function findFor(PortalUserId $actor,OrganizationId $organization): ?OrganizationMembership
            {
                return $this->membership;
            }
        };
        $resources=new class($owner) implements PortalResourceAuthority {
            public function __construct(private OrganizationId $owner) {}
            public function ownerOf(OrganizationId $organization,string $opaqueResourceReference): ?OrganizationId
            {
                return $opaqueResourceReference==='lab-gsc-resource'?$this->owner:null;
            }
        };
        $source=new class($grant??$this->grant(),$noGrant,$error) implements RecordedGscGrantReviewSource {
            public function __construct(
                private array $grant,private bool $noGrant,private bool $error
            ) {}
            public function inspect(array $serverResolvedContext,string $exactSiteUrl,int $trustedNow): ?array
            {
                if ($this->error) throw new RuntimeException('SECRET_TENANT_OR_TOKEN');
                if ($this->noGrant || $exactSiteUrl!=='sc-domain:example.com' ||
                    $serverResolvedContext!==[
                        'tenantRef'=>'demo-tenant','actorRef'=>'portal-user-a'
                    ]) return null;
                return $this->grant;
            }
        };
        return new SeoFunnelTenantPropertyBoundary(
            $sessions,$memberships,$resources,$source
        );
    }

    private function review(
        SeoFunnelTenantPropertyBoundary $boundary,
        ?array $dto = null,
        mixed $session = 'lab-opaque-session',
        mixed $org = 'demo-tenant',
        mixed $resource = 'lab-gsc-resource',
        mixed $siteUrl = 'sc-domain:example.com',
        mixed $now = self::NOW,
    ): ?array {
        return $boundary->inspect(
            $session,$org,$resource,$siteUrl,$dto??$this->dto(),$now
        );
    }

    public function test_matching_synthetic_conditions_never_grant_real_customer_access(): void
    {
        $record=$this->review($this->fixture());
        self::assertNotNull($record);
        self::assertSame('portal-seo-funnel-scoped-review-lab-v1',$record['contractVersion']);
        self::assertTrue($record['portalSyntheticChecksPass']);
        self::assertTrue($record['recordedGrantFreshInSuppliedReview']);
        self::assertSame([
            'VISITAS'=>'NOT_AVAILABLE','LEADS'=>'NOT_AVAILABLE',
            'CLIENTES'=>'NOT_AVAILABLE'
        ],$record['funnelStages']);
        self::assertSame(['clicks'=>3,'impressions'=>30,'ctr'=>0.1],
            $record['searchDiscoveryForLabReview']);
        foreach ([
            'googleApiCheckedNow','sessionIssuerVerifiedForProduction',
            'tenantMembershipVerifiedForProduction',
            'propertyAuthorizationVerifiedForProduction','oauthVerifiedForImagine',
            'customerDisplayAuthorized','productionReadAuthorized',
            'publicationAuthorized','networkPerformed'
        ] as $flag) self::assertFalse($record[$flag],$flag);
        self::assertFalse(SeoFunnelTenantPropertyBoundary::authorizesHttp());
        self::assertFalse(SeoFunnelTenantPropertyBoundary::authorizesGoogleRequest());
        self::assertFalse(SeoFunnelTenantPropertyBoundary::authorizesCustomerDisplay());
        self::assertStringNotContainsString('example.com',json_encode($record));
        self::assertStringNotContainsString('portal-user-a',json_encode($record));
    }

    public function test_missing_mfa_stale_session_and_account_revision_are_denied(): void
    {
        $actor=new PortalUserId('portal-user-a');
        foreach ([
            new PortalSessionSnapshot($actor,true,false,self::NOW-500,self::NOW+20,2,2),
            new PortalSessionSnapshot($actor,true,true,self::NOW-500,self::NOW-1,2,2),
            new PortalSessionSnapshot($actor,false,true,self::NOW-500,self::NOW+20,2,2),
            new PortalSessionSnapshot($actor,true,true,self::NOW-500,self::NOW+20,3,2)
        ] as $session) self::assertNull($this->review($this->fixture(session:$session)));
    }

    public function test_cross_tenant_actor_owner_and_permission_are_denied(): void
    {
        $actor=new PortalUserId('portal-user-a');
        $org=new OrganizationId('demo-tenant');
        foreach ([
            new OrganizationMembership(new PortalUserId('other-actor'),$org,
                OrganizationRole::Member,MembershipStatus::Active,[Permission::ViewSeo]),
            new OrganizationMembership($actor,new OrganizationId('other-tenant'),
                OrganizationRole::Member,MembershipStatus::Active,[Permission::ViewSeo]),
            new OrganizationMembership($actor,$org,
                OrganizationRole::Member,MembershipStatus::Revoked,[Permission::ViewSeo]),
            new OrganizationMembership($actor,$org,
                OrganizationRole::Member,MembershipStatus::Active,[Permission::ViewServices])
        ] as $membership) self::assertNull(
            $this->review($this->fixture(membership:$membership))
        );
        self::assertNull($this->review($this->fixture(owner:new OrganizationId('other-tenant'))));
        self::assertNull($this->review($this->fixture(),org:'other-tenant'));
    }

    public function test_wrong_resource_and_property_cannot_borrow_matching_grant(): void
    {
        self::assertNull($this->review($this->fixture(),resource:'foreign-resource'));
        self::assertNull($this->review($this->fixture(),siteUrl:'sc-domain:other.example'));
        self::assertNull($this->review($this->fixture(),siteUrl:'https://example.com/'));
        self::assertNull($this->review($this->fixture(),siteUrl:'sc-domain:EXAMPLE.com'));
    }

    public function test_absent_expired_wrong_actor_scope_or_forged_grant_denies(): void
    {
        self::assertNull($this->review($this->fixture(noGrant:true)));
        $base=$this->grant();
        foreach ([
            ['expiresAt'=>self::NOW-1],
            ['verifiedAt'=>self::NOW-901],
            ['verifiedAt'=>self::NOW+1],
            ['actorRef'=>'other-actor'],
            ['tenantRef'=>'other-tenant'],
            ['propertyFingerprint'=>str_repeat('f',64)],
            ['recordedScope'=>'webmasters'],
            ['recordedGrantFresh'=>false],
            ['googleApiCheckedNow'=>true],
            ['customerDisplayAuthorized'=>true],
            ['releaseStatus'=>'READY'],
            ['extraToken'=>'PRIVATE_KEY'],
        ] as $mutation) {
            self::assertNull($this->review($this->fixture(
                grant:array_replace($base,$mutation)
            )));
        }
    }

    public function test_forged_dto_user_claims_and_provider_exceptions_deny(): void
    {
        $dto=$this->dto();
        $dto['stages'][0]['value']=100;
        self::assertNull($this->review($this->fixture(),dto:$dto));
        $dto=$this->dto();
        $dto['customerDisplayAuthorized']=true;
        self::assertNull($this->review($this->fixture(),dto:$dto));
        $dto=$this->dto();
        $dto['scope']['propertyFingerprint']=str_repeat('0',64);
        self::assertNull($this->review($this->fixture(),dto:$dto));
        self::assertNull($this->review($this->fixture(error:true)));
        self::assertNull($this->review($this->fixture(),session:'crm-session'));
        self::assertNull($this->review($this->fixture(),session:''));
    }

    public function test_non_lab_and_non_testing_always_deny(): void
    {
        $boundary=$this->fixture();
        putenv('APP_ENV=production');
        self::assertNull($this->review($boundary));
        putenv('APP_ENV=testing');
        putenv('PORTAL_SEO_F107_LAB=0');
        self::assertNull($this->review($boundary));
        putenv('PORTAL_SEO_F107_LAB=1');
        putenv('PORTAL_SEO_F106_LAB=0');
        self::assertNull($this->review($boundary));
    }
}
