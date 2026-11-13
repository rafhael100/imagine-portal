<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Organizations\OrganizationId;
use App\Domain\ServiceRequests\ServiceRequestStatus;
use Illuminate\Support\Facades\Blade;
use PDO;
use PHPUnit\Framework\Attributes\DoesNotPerformAssertions;
use RuntimeException;
use Tests\Support\DisposablePg18ProjectInboxReader;
use Tests\TestCase;

/**
 * F14: genuine disposable PG18 -> domain snapshot -> offline Blade table.
 * All references are fictional; absolutely no tenant/customer HTTP route.
 */
final class PortalPg18ToUiRequestProjectionTest extends TestCase
{
    private const ORG_A = '11111111-1111-4111-8111-111111111111';
    private const ORG_B = '22222222-2222-4222-8222-222222222222';
    private const PROJECT_A = '33333333-3333-4333-8333-333333333333';
    private const PROJECT_B = '66666666-6666-4666-8666-666666666666';
    private PDO $admin;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('APP_ENV') !== 'testing'
            || getenv('PORTAL_BROWSER_PG_LAB') !== 'DISPOSABLE_PG18'
            || getenv('PGHOST') !== '127.0.0.1'
            || getenv('PGPORT') !== '5432'
            || getenv('PGDATABASE') !== 'portal_browser_lab') {
            self::fail('Synthetic PostgreSQL 18 CI laboratory required.');
        }
        $this->admin=self::connection('postgres',(string) getenv('PGPASSWORD'));
        self::assertSame('portal_browser_lab',
            $this->admin->query('SELECT current_database()')->fetchColumn());
        self::assertSame(18,(int)$this->admin->query(
            "SELECT current_setting('server_version_num')::integer/10000"
        )->fetchColumn());

        $this->admin->exec(<<<'SQL'
TRUNCATE public.portal_service_request_transitions,
 public.portal_service_requests,public.portal_service_project_checkpoints,
 public.portal_service_projects,public.portal_service_resource_claims,
 public.portal_email_proof_challenges,public.portal_auth_sessions,
 public.portal_membership_grants,public.portal_memberships,public.portal_identities,
 public.portal_organizations,public.portal_auth_attempt_buckets CASCADE;
INSERT INTO public.portal_organizations(id,reference,display_name,status)
VALUES ('11111111-1111-4111-8111-111111111111','ui-fixture-a','UI Fiction A','active'),
       ('22222222-2222-4222-8222-222222222222','ui-fixture-b','UI Fiction B','active');
INSERT INTO public.portal_service_projects(id,organization_id,project_ref,product_code)
VALUES ('33333333-3333-4333-8333-333333333333',
        '11111111-1111-4111-8111-111111111111',
        'DEMO-SVC-ONEPAGE','sites_onepage'),
       ('66666666-6666-4666-8666-666666666666',
        '22222222-2222-4222-8222-222222222222',
        'DEMO-SVC-FOREIGN','sites_landing_page');
INSERT INTO public.portal_service_requests(id,organization_id,project_id,request_ref)
VALUES ('44444444-4444-4444-8444-444444444444',
        '11111111-1111-4111-8111-111111111111',
        '33333333-3333-4333-8333-333333333333','DEMO-REQ-A'),
       ('55555555-5555-4555-8555-555555555555',
        '11111111-1111-4111-8111-111111111111',
        '33333333-3333-4333-8333-333333333333','DEMO-REQ-B'),
       ('77777777-7777-4777-8777-777777777777',
        '22222222-2222-4222-8222-222222222222',
        '66666666-6666-4666-8666-666666666666','DEMO-REQ-FOREIGN');
SQL);
        $this->admin->exec(<<<'SQL'
UPDATE public.portal_service_requests
   SET status='submitted'
 WHERE request_ref='DEMO-REQ-B';
UPDATE public.portal_service_requests
   SET status='in_review'
 WHERE request_ref='DEMO-REQ-B';
SQL);
    }

    private static function connection(string $user,string $secret): PDO
    {
        return new PDO(
            'pgsql:host=127.0.0.1;port=5432;dbname=portal_browser_lab',
            $user,$secret,[
                PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES=>false,
            ],
        );
    }

    private function reader(?PDO $pdo=null): DisposablePg18ProjectInboxReader
    {
        return new DisposablePg18ProjectInboxReader($pdo??$this->admin);
    }

    private static function org(string $value=self::ORG_A): OrganizationId
    {
        return new OrganizationId($value);
    }

    public function test_pg18_rows_are_projected_into_real_typed_domain_objects(): void
    {
        $result=$this->reader()->project(self::org(),self::PROJECT_A);
        self::assertTrue($result->isPreparedPreview());
        self::assertSame('preview_ready',$result->reason);
        self::assertSame(2,$result->total);
        self::assertNull($result->nextOffset);
        self::assertCount(2,$result->items);
        self::assertSame('DEMO-REQ-A',$result->items[0]->requestId);
        self::assertSame('DEMO-REQ-B',$result->items[1]->requestId);
        self::assertSame('DEMO-SVC-ONEPAGE',$result->items[1]->serviceReference);
        self::assertSame(self::ORG_A,$result->items[0]->organizationId->value);
        self::assertSame(ServiceRequestStatus::Draft,$result->items[0]->status);
        self::assertSame(ServiceRequestStatus::InReview,$result->items[1]->status);
        self::assertSame(3,$result->items[1]->revision);
        self::assertFalse($result->mayExposeCustomerData());
        self::assertFalse(DisposablePg18ProjectInboxReader::authorizesCustomerRead());
        self::assertFalse(DisposablePg18ProjectInboxReader::enablesRoute());
        self::assertFalse($this->admin->inTransaction());
    }

    public function test_query_scope_excludes_foreign_tenant_and_rejects_wrong_owner(): void
    {
        $projection=$this->reader();
        $one=$projection->project(self::org(),self::PROJECT_A);
        self::assertCount(2,$one->items);
        foreach ($one->items as $item) {
            self::assertStringNotContainsString('FOREIGN',$item->requestId);
        }
        $two=$projection->project(self::org(self::ORG_B),self::PROJECT_B);
        self::assertCount(1,$two->items);
        self::assertSame('DEMO-REQ-FOREIGN',$two->items[0]->requestId);
        $unauthorized=$projection->project(self::org(),self::PROJECT_B);
        self::assertSame('invalid_preview_input',$unauthorized->reason);
        self::assertSame([],$unauthorized->items);
        self::assertSame(0,$unauthorized->total);
        self::assertFalse($unauthorized->mayExposeCustomerData());
    }

    public function test_projection_can_render_an_accessible_inert_blade_table_without_routes(): void
    {
        $routesBefore=count($this->app['router']->getRoutes());
        $result=$this->reader()->project(self::org(),self::PROJECT_A);
        $rows=array_map(
            static fn ($item): array => [
                $item->requestId,$item->serviceReference,
                $item->status->label(),'Revisão fictícia '.$item->revision,
            ],
            $result->items,
        );
        $html=Blade::render(
            '<x-portal.table caption="Consulta sintética PG18 — somente testes" '
            .':columns="$cols" :rows="$rows" />',
            ['cols'=>['Solicitação','Projeto','Estado','Revisão'],'rows'=>$rows],
        );
        self::assertStringContainsString('DEMO-REQ-A',$html);
        self::assertStringContainsString('DEMO-REQ-B',$html);
        self::assertStringContainsString('Em análise',$html);
        self::assertStringContainsString('Revisão fictícia 3',$html);
        self::assertStringContainsString('<caption>Consulta sintética PG18',$html);
        self::assertStringContainsString('role="region"',$html);
        self::assertStringContainsString('tabindex="0"',$html);
        self::assertStringNotContainsString('DEMO-REQ-FOREIGN',$html);
        self::assertStringNotContainsString('<form',$html);
        self::assertStringNotContainsString('<script',$html);
        self::assertSame($routesBefore,count($this->app['router']->getRoutes()));
        $this->get('/portal/requests')->assertNotFound();
    }

    public function test_project_retirement_and_organization_suspension_refuse_all_rows(): void
    {
        $reader=$this->reader();
        $this->admin->exec(
            "UPDATE public.portal_service_projects SET state='retired'
             WHERE id='33333333-3333-4333-8333-333333333333'"
        );
        $retired=$reader->project(self::org(),self::PROJECT_A);
        self::assertSame([],$retired->items);
        self::assertSame(0,$retired->total);

        $this->admin->exec(
            "UPDATE public.portal_organizations SET status='suspended'
             WHERE id='22222222-2222-4222-8222-222222222222'"
        );
        $suspended=$reader->project(self::org(self::ORG_B),self::PROJECT_B);
        self::assertSame([],$suspended->items);
        self::assertSame(0,$suspended->total);
        self::assertFalse($suspended->mayExposeCustomerData());
    }

    public function test_unknown_and_malformed_ids_fail_closed_without_sql_side_effects(): void
    {
        $reader=$this->reader();
        foreach ([
            'not-a-uuid',self::PROJECT_A."';DROP TABLE portal_service_requests;--",
            '00000000-0000-4000-8000-000000000000',
        ] as $id) {
            $result=$reader->project(self::org(),$id);
            self::assertSame([],$result->items);
            self::assertSame(0,$result->total);
        }
        $notUuidOrg=$reader->project(new OrganizationId('DEMO-ORG-A'),self::PROJECT_A);
        self::assertSame([],$notUuidOrg->items);
        self::assertSame(3,(int)$this->admin->query(
            'SELECT count(*) FROM public.portal_service_requests'
        )->fetchColumn());
        self::assertFalse($this->admin->inTransaction());
    }

    public function test_runtime_role_cannot_use_reader_as_a_database_privilege_bypass(): void
    {
        $runtime=self::connection(
            'portal_browser_runtime',(string)getenv('PORTAL_BROWSER_RUNTIME_PASSWORD')
        );
        $result=$this->reader($runtime)->project(self::org(),self::PROJECT_A);
        self::assertSame('invalid_preview_input',$result->reason);
        self::assertSame([],$result->items);
        self::assertSame(0,$result->total);
        self::assertFalse($result->mayExposeCustomerData());
        self::assertFalse($runtime->inTransaction());
    }

    public function test_more_than_fifty_requests_fails_closed_without_truncation(): void
    {
        $this->admin->exec(<<<'SQL'
INSERT INTO public.portal_service_requests
  (id,organization_id,project_id,request_ref)
SELECT gen_random_uuid(),
       '11111111-1111-4111-8111-111111111111'::uuid,
       '33333333-3333-4333-8333-333333333333'::uuid,
       'DEMO-REQ-LIMIT-'||lpad(n::text,3,'0')
  FROM generate_series(1,51) AS n
SQL);
        $result=$this->reader()->project(self::org(),self::PROJECT_A);
        self::assertSame('invalid_preview_input',$result->reason);
        self::assertSame([],$result->items);
        self::assertSame(0,$result->total);
        self::assertFalse($this->admin->inTransaction());
    }

    public function test_open_transaction_cannot_be_reused_as_projection_context(): void
    {
        $this->admin->beginTransaction();
        try {
            $result=$this->reader()->project(self::org(),self::PROJECT_A);
            self::assertSame([],$result->items);
            self::assertSame(0,$result->total);
        } finally {
            $this->admin->rollBack();
        }
        self::assertFalse($this->admin->inTransaction());
    }
}
