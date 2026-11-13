<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Organizations\OrganizationId;
use Illuminate\Support\Facades\Blade;
use PDO;
use Tests\Support\DisposablePg18ProjectPreparationReader;
use Tests\TestCase;

final class PortalPg18ProjectPreparationProjectionTest extends TestCase
{
    private const ORG='11111111-1111-4111-8111-111111111111';
    private const OTHER='22222222-2222-4222-8222-222222222222';
    private const ONE='33333333-3333-4333-8333-333333333333';
    private const LANDING='44444444-4444-4444-8444-444444444444';
    private const PERF='55555555-5555-4555-8555-555555555555';
    private const FOREIGN='66666666-6666-4666-8666-666666666666';

    private PDO $db;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('APP_ENV')!=='testing'
            || getenv('PORTAL_BROWSER_PG_LAB')!=='DISPOSABLE_PG18'
            || getenv('PGHOST')!=='127.0.0.1'
            || getenv('PGPORT')!=='5432'
            || getenv('PGDATABASE')!=='portal_browser_lab') {
            self::fail('Disposable PG18 preparation CI laboratory required.');
        }
        $this->db=self::connection('postgres',(string)getenv('PGPASSWORD'));
        self::assertSame('portal_browser_lab',
            $this->db->query('SELECT current_database()')->fetchColumn());
        self::assertSame(18,(int)$this->db->query(
            "SELECT current_setting('server_version_num')::integer/10000"
        )->fetchColumn());
        $this->db->exec(<<<'SQL'
TRUNCATE public.portal_service_request_transitions,
 public.portal_service_requests,public.portal_service_project_checkpoints,
 public.portal_service_projects,public.portal_service_resource_claims,
 public.portal_email_proof_challenges,public.portal_auth_sessions,
 public.portal_membership_grants,public.portal_memberships,public.portal_identities,
 public.portal_organizations,public.portal_auth_attempt_buckets CASCADE;
INSERT INTO public.portal_organizations(id,reference,display_name,status)
VALUES ('11111111-1111-4111-8111-111111111111','prepare-org-a','Prepare A','active'),
       ('22222222-2222-4222-8222-222222222222','prepare-org-b','Prepare B','active');
INSERT INTO public.portal_service_projects(id,organization_id,project_ref,product_code)
VALUES ('33333333-3333-4333-8333-333333333333',
        '11111111-1111-4111-8111-111111111111','DEMO-ONEPAGE','sites_onepage'),
       ('44444444-4444-4444-8444-444444444444',
        '11111111-1111-4111-8111-111111111111','DEMO-LANDING','sites_landing_page'),
       ('55555555-5555-4555-8555-555555555555',
        '11111111-1111-4111-8111-111111111111','DEMO-PERFORMANCE','performance_ai'),
       ('66666666-6666-4666-8666-666666666666',
        '22222222-2222-4222-8222-222222222222','DEMO-FOREIGN','sites_onepage');

INSERT INTO public.portal_service_project_checkpoints
    (id,project_id,organization_id,checkpoint_code)
SELECT gen_random_uuid(),p.id,p.organization_id,c.code
FROM public.portal_service_projects p
JOIN LATERAL (
    VALUES ('sites_onepage','briefing'),
           ('sites_onepage','content'),
           ('sites_onepage','domain'),
           ('sites_landing_page','briefing'),
           ('sites_landing_page','content'),
           ('sites_landing_page','consent'),
           ('performance_ai','goals'),
           ('performance_ai','ad_accounts'),
           ('performance_ai','creatives'),
           ('performance_ai','budget')
) AS c(product,code) ON c.product=p.product_code;

UPDATE public.portal_service_project_checkpoints
SET state='reviewed'
WHERE project_id='33333333-3333-4333-8333-333333333333';
UPDATE public.portal_service_project_checkpoints
SET state='reviewed'
WHERE project_id='55555555-5555-4555-8555-555555555555'
AND checkpoint_code IN ('goals','ad_accounts');
SQL);
    }

    private static function connection(string $user,string $password): PDO
    {
        return new PDO(
            'pgsql:host=127.0.0.1;port=5432;dbname=portal_browser_lab',
            $user,$password,[
                PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES=>false,
            ],
        );
    }

    private function reader(?PDO $pdo=null): DisposablePg18ProjectPreparationReader
    {
        return new DisposablePg18ProjectPreparationReader($pdo??$this->db);
    }

    public function test_three_real_pg18_project_checklists_reach_five_column_inert_preview(): void
    {
        $result=$this->reader()->project(new OrganizationId(self::ORG));
        self::assertSame('preview_ready',$result->reason);
        self::assertCount(3,$result->rows);
        $byRef=[];
        foreach ($result->rows as $row) {
            self::assertCount(5,$row);
            $byRef[$row[1]]=$row;
        }
        self::assertSame('Sites Imagine · Onepage',$byRef['DEMO-ONEPAGE'][0]);
        self::assertSame('3/3 itens revisados',$byRef['DEMO-ONEPAGE'][3]);
        self::assertSame('Sem itens pendentes no exemplo',$byRef['DEMO-ONEPAGE'][4]);
        self::assertSame('Sites Imagine · Landing Page',$byRef['DEMO-LANDING'][0]);
        self::assertSame('0/3 itens revisados',$byRef['DEMO-LANDING'][3]);
        self::assertStringContainsString('Consentimento',$byRef['DEMO-LANDING'][4]);
        self::assertSame('Performance AI',$byRef['DEMO-PERFORMANCE'][0]);
        self::assertSame('2/4 itens revisados',$byRef['DEMO-PERFORMANCE'][3]);
        self::assertStringContainsString('Criativos',$byRef['DEMO-PERFORMANCE'][4]);
        self::assertStringContainsString('Orçamento',$byRef['DEMO-PERFORMANCE'][4]);
        self::assertStringNotContainsString('ad_accounts',$byRef['DEMO-PERFORMANCE'][4]);
        self::assertStringNotContainsString('creatives',$byRef['DEMO-PERFORMANCE'][4]);
        self::assertFalse($result->authorizesService());
        self::assertFalse($result->authorizesPublication());
        self::assertFalse($result->authorizesAdSpend());
        self::assertFalse($result->authorizesUniversityAccess());
        self::assertFalse(DisposablePg18ProjectPreparationReader::authorizesCustomerAccess());
        self::assertFalse(DisposablePg18ProjectPreparationReader::authorizesReview());
        self::assertFalse($this->db->inTransaction());
    }

    public function test_preparation_rows_render_to_accessible_blade_without_routes_or_postbacks(): void
    {
        $before=count($this->app['router']->getRoutes());
        $result=$this->reader()->project(new OrganizationId(self::ORG));
        $html=Blade::render(
            '<x-portal.table caption="Preparação sintética PG18" '
            .':columns="$cols" :rows="$rows" />',
            ['cols'=>['Serviço','Projeto','Estado','Itens','Pendências'],
             'rows'=>$result->rows],
        );
        foreach (['DEMO-ONEPAGE','DEMO-LANDING','DEMO-PERFORMANCE',
                  '3/3 itens revisados','0/3 itens revisados',
                  '2/4 itens revisados','Sites Imagine · Onepage'] as $term) {
            self::assertStringContainsString($term,$html);
        }
        self::assertStringContainsString('<caption>Preparação sintética PG18</caption>',$html);
        self::assertStringContainsString('role="region"',$html);
        self::assertStringContainsString('tabindex="0"',$html);
        self::assertStringNotContainsString('DEMO-FOREIGN',$html);
        self::assertStringNotContainsString('<form',$html);
        self::assertStringNotContainsString('<script',$html);
        self::assertSame($before,count($this->app['router']->getRoutes()));
        $this->get('/portal/preparation')->assertNotFound();
    }

    public function test_foreign_company_is_not_mixed_into_same_preview(): void
    {
        $reader=$this->reader();
        $a=$reader->project(new OrganizationId(self::ORG));
        $b=$reader->project(new OrganizationId(self::OTHER));
        self::assertCount(3,$a->rows);
        self::assertCount(1,$b->rows);
        self::assertSame('DEMO-FOREIGN',$b->rows[0][1]);
        foreach ($a->rows as $row) {
            self::assertNotSame('DEMO-FOREIGN',$row[1]);
        }
        self::assertFalse($b->authorizesService());
    }

    public function test_retired_project_or_suspended_company_returns_no_partial_rows(): void
    {
        $this->db->exec(
            "UPDATE public.portal_service_projects SET state='retired'
             WHERE id='33333333-3333-4333-8333-333333333333'"
        );
        $retired=$this->reader()->project(new OrganizationId(self::ORG));
        self::assertSame('invalid_preview_input',$retired->reason);
        self::assertSame([],$retired->rows);
        $this->db->exec(
            "UPDATE public.portal_organizations SET status='suspended'
             WHERE id='22222222-2222-4222-8222-222222222222'"
        );
        $suspended=$this->reader()->project(new OrganizationId(self::OTHER));
        self::assertSame('invalid_preview_input',$suspended->reason);
        self::assertSame([],$suspended->rows);
    }

    public function test_retired_source_claim_refuses_checklist_projection(): void
    {
        $this->db->exec(<<<'SQL'
INSERT INTO public.portal_service_resource_claims
(id,organization_id,source_system,source_resource_ref,product_code)
VALUES ('77777777-7777-4777-8777-777777777777',
        '11111111-1111-4111-8111-111111111111',
        'imagine_sites','SITES-SYNTH-ONEPAGE','sites_onepage');
UPDATE public.portal_service_projects
SET source_claim_id='77777777-7777-4777-8777-777777777777'
WHERE id='33333333-3333-4333-8333-333333333333';
UPDATE public.portal_service_resource_claims
SET state='retired'
WHERE id='77777777-7777-4777-8777-777777777777';
SQL);
        $result=$this->reader()->project(new OrganizationId(self::ORG));
        self::assertSame('invalid_preview_input',$result->reason);
        self::assertSame([],$result->rows);
    }

    public function test_large_project_set_cannot_be_silently_truncated(): void
    {
        $this->db->exec(<<<'SQL'
INSERT INTO public.portal_service_projects
(id,organization_id,project_ref,product_code)
SELECT gen_random_uuid(),
 '11111111-1111-4111-8111-111111111111'::uuid,
 'DEMO-OVERFLOW-'||lpad(n::text,2,'0'),
 'sites_onepage'
FROM generate_series(1,11) n
SQL);
        $result=$this->reader()->project(new OrganizationId(self::ORG));
        self::assertSame([],$result->rows);
        self::assertSame('invalid_preview_input',$result->reason);
    }

    public function test_runtime_role_and_malformed_organization_are_fail_closed(): void
    {
        $runtime=self::connection(
            'portal_browser_runtime',
            (string)getenv('PORTAL_BROWSER_RUNTIME_PASSWORD'),
        );
        $result=$this->reader($runtime)->project(new OrganizationId(self::ORG));
        self::assertSame('invalid_preview_input',$result->reason);
        self::assertSame([],$result->rows);
        $notUuid=$this->reader()->project(new OrganizationId('FAKE-ORG-NO-UUID'));
        self::assertSame([],$notUuid->rows);
        self::assertSame('invalid_preview_input',$notUuid->reason);
        self::assertFalse($this->db->inTransaction());
    }

    public function test_existing_transaction_cannot_be_injected_into_projection(): void
    {
        $reader=$this->reader();
        $this->db->beginTransaction();
        try {
            $result=$reader->project(new OrganizationId(self::ORG));
            self::assertSame('invalid_preview_input',$result->reason);
            self::assertSame([],$result->rows);
        } finally {
            $this->db->rollBack();
        }
        self::assertFalse($this->db->inTransaction());
    }
}
