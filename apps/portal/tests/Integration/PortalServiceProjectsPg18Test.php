<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PDOException;
use Tests\TestCase;

/**
 * PostgreSQL 18 disposable-only tests; synthetic organizations and projects.
 *
 * No customer service may be granted from this database candidate schema.
 */
final class PortalServiceProjectsPg18Test extends TestCase
{
    private const ORG_A = '11111111-1111-4111-8111-111111111111';
    private const ORG_B = '22222222-2222-4222-8222-222222222222';
    private const CLAIM_A = '33333333-3333-4333-8333-333333333333';
    private const CLAIM_OTHER = '44444444-4444-4444-8444-444444444444';
    private const CLAIM_PERF = '55555555-5555-4555-8555-555555555555';
    private const PROJECT_A = '66666666-6666-4666-8666-666666666666';
    private const PROJECT_B = '77777777-7777-4777-8777-777777777777';
    private const PROJECT_C = '88888888-8888-4888-8888-888888888888';

    private PDO $admin;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('APP_ENV') !== 'testing'
            || getenv('PORTAL_BROWSER_PG_LAB') !== 'DISPOSABLE_PG18'
            || getenv('PGHOST') !== '127.0.0.1'
            || getenv('PGPORT') !== '5432'
            || getenv('PGDATABASE') !== 'portal_browser_lab') {
            self::fail('Explicit disposable PostgreSQL 18 service-project laboratory required.');
        }
        $this->admin=self::connect('postgres',(string)getenv('PGPASSWORD'));
        self::assertSame('portal_browser_lab',$this->admin->query(
            'SELECT current_database()'
        )->fetchColumn());
        self::assertSame(18,(int)$this->admin->query(
            "SELECT current_setting('server_version_num')::integer/10000"
        )->fetchColumn());

        $this->admin->exec(<<<'SQL'
TRUNCATE public.portal_service_projects, public.portal_service_resource_claims,
 public.portal_email_proof_challenges, public.portal_auth_sessions,
 public.portal_membership_grants, public.portal_memberships,
 public.portal_identities, public.portal_organizations,
 public.portal_auth_attempt_buckets CASCADE
SQL);
        $this->admin->exec(<<<'SQL'
INSERT INTO public.portal_organizations(id,reference,display_name,status)
VALUES
 ('11111111-1111-4111-8111-111111111111','fixture-proj-a','Fixture A','active'),
 ('22222222-2222-4222-8222-222222222222','fixture-proj-b','Fixture B','active')
SQL);
        $this->admin->exec(<<<'SQL'
INSERT INTO public.portal_service_resource_claims
 (id,organization_id,source_system,source_resource_ref,product_code)
VALUES
 ('33333333-3333-4333-8333-333333333333',
  '11111111-1111-4111-8111-111111111111',
  'imagine_sites','SITES-ONEPAGE-FAKE','sites_onepage'),
 ('44444444-4444-4444-8444-444444444444',
  '22222222-2222-4222-8222-222222222222',
  'imagine_sites','SITES-FOREIGN-FAKE','sites_onepage'),
 ('55555555-5555-4555-8555-555555555555',
  '11111111-1111-4111-8111-111111111111',
  'imagine_performance','PERF-FAKE','performance_ai')
SQL);
    }

    private static function connect(string $user, string $password): PDO
    {
        return new PDO(
            'pgsql:host=127.0.0.1;port=5432;dbname=portal_browser_lab',
            $user,$password,[
                PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES=>false,
            ],
        );
    }

    private static function denied(PDO $db,string $sql,array $parameters=[],
                                   string $state='42501'): void
    {
        try {
            $q=$db->prepare($sql);
            $q->execute($parameters);
        } catch (PDOException $error) {
            self::assertSame($state,(string)$error->getCode());
            return;
        }
        self::fail('Unsafe PostgreSQL project candidate operation accepted.');
    }

    private function createProject(
        string $id=self::PROJECT_A,
        string $organization=self::ORG_A,
        string $reference='FIXTURE-PROJECT-1',
        string $product='sites_onepage',
    ): void {
        $q=$this->admin->prepare(
            'INSERT INTO public.portal_service_projects
                 (id,organization_id,project_ref,product_code)
             VALUES(CAST(? AS uuid),CAST(? AS uuid),?,?)'
        );
        $q->execute([$id,$organization,$reference,$product]);
    }

    private function bind(string $id=self::PROJECT_A,string $claim=self::CLAIM_A): void
    {
        $q=$this->admin->prepare(
            'UPDATE public.portal_service_projects
             SET source_claim_id=CAST(? AS uuid) WHERE id=CAST(? AS uuid)'
        );
        $q->execute([$claim,$id]);
    }

    /** @return array<string,mixed> */
    private function state(string $id=self::PROJECT_A): array
    {
        $q=$this->admin->prepare(
            'SELECT organization_id,project_ref,product_code,source_claim_id,
                    state,project_revision,created_at,updated_at
             FROM public.portal_service_projects WHERE id=CAST(? AS uuid)'
        );
        $q->execute([$id]);
        $row=$q->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        return $row;
    }

    public function test_project_table_has_force_rls_no_policies_and_runtime_has_zero_access(): void
    {
        $this->createProject();
        $flags=$this->admin->query(
            "SELECT relrowsecurity,relforcerowsecurity
             FROM pg_class WHERE oid='public.portal_service_projects'::regclass"
        )->fetch(PDO::FETCH_ASSOC);
        self::assertContains($flags['relrowsecurity'],['t',true,'1',1]);
        self::assertContains($flags['relforcerowsecurity'],['t',true,'1',1]);
        self::assertSame(0,(int)$this->admin->query(
            "SELECT count(*) FROM pg_policies
             WHERE schemaname='public' AND tablename='portal_service_projects'"
        )->fetchColumn());
        $runtime=self::connect('portal_browser_runtime',
            (string)getenv('PORTAL_BROWSER_RUNTIME_PASSWORD'));
        self::denied($runtime,'SELECT * FROM public.portal_service_projects');
        self::denied($runtime,
            "UPDATE public.portal_service_projects SET state='retired'");
        self::denied($runtime,
            "INSERT INTO public.portal_service_projects
                 (id,organization_id,project_ref,product_code)
             VALUES(gen_random_uuid(),CAST(? AS uuid),'NOT-ALLOWED','sites_onepage')",
            [self::ORG_A],
        );
    }

    public function test_unbound_draft_can_bind_matching_source_only_once_then_review_and_retire(): void
    {
        $this->createProject();
        $draft=$this->state();
        self::assertSame('draft',$draft['state']);
        self::assertNull($draft['source_claim_id']);
        self::assertSame(1,(int)$draft['project_revision']);
        // F12 adds an explicit readiness trigger: incomplete checklists are
        // rejected as authorization failures before the table CHECK.
        self::denied($this->admin,
            "UPDATE public.portal_service_projects SET state='review'");
        $this->bind();
        $bound=$this->state();
        self::assertSame(self::CLAIM_A,$bound['source_claim_id']);
        self::assertSame(2,(int)$bound['project_revision']);
        // The F12 checkpoint migration is present in this same disposable
        // database: a bound project must still pass ALL product requirements.
        $this->admin->exec(<<<'SQL'
INSERT INTO public.portal_service_project_checkpoints
    (id,project_id,organization_id,checkpoint_code)
SELECT gen_random_uuid(), p.id, p.organization_id, code
  FROM public.portal_service_projects p
 CROSS JOIN (VALUES('briefing'),('content'),('domain')) AS required(code)
 WHERE p.id='66666666-6666-4666-8666-666666666666'
SQL);
        self::denied($this->admin,
            "UPDATE public.portal_service_projects SET state='review'");
        $this->admin->exec(<<<'SQL'
UPDATE public.portal_service_project_checkpoints
   SET state='reviewed'
 WHERE project_id='66666666-6666-4666-8666-666666666666'
SQL);
        $this->admin->exec("UPDATE public.portal_service_projects SET state='review'");
        self::assertSame('review',$this->state()['state']);
        self::assertSame(3,(int)$this->state()['project_revision']);
        $this->admin->exec("UPDATE public.portal_service_projects SET state='retired'");
        self::assertSame('retired',$this->state()['state']);
        self::assertSame(4,(int)$this->state()['project_revision']);
        self::denied($this->admin,
            "UPDATE public.portal_service_projects SET state='draft'");
        self::denied($this->admin,"DELETE FROM public.portal_service_projects");
    }

    public function test_project_identity_and_tenant_cannot_be_changed_or_copied(): void
    {
        $this->createProject();
        foreach ([
            "UPDATE public.portal_service_projects SET organization_id='22222222-2222-4222-8222-222222222222'",
            "UPDATE public.portal_service_projects SET project_ref='FORGED-REF'",
            "UPDATE public.portal_service_projects SET product_code='sites_landing_page'",
            "UPDATE public.portal_service_projects SET id='99999999-9999-4999-8999-999999999999'",
            'UPDATE public.portal_service_projects SET project_revision=999',
            "UPDATE public.portal_service_projects SET updated_at=clock_timestamp()+interval '1 minute'",
        ] as $sql) {
            self::denied($this->admin,$sql);
        }
        self::assertSame(self::ORG_A,$this->state()['organization_id']);
        self::assertSame('FIXTURE-PROJECT-1',$this->state()['project_ref']);
    }

    public function test_foreign_product_and_retired_source_claim_cannot_bind_to_another_project(): void
    {
        $this->createProject();
        self::denied($this->admin,
            "UPDATE public.portal_service_projects
             SET source_claim_id='44444444-4444-4444-8444-444444444444'");
        self::denied($this->admin,
            "UPDATE public.portal_service_projects
             SET source_claim_id='55555555-5555-4555-8555-555555555555'");
        $this->admin->exec(
            "UPDATE public.portal_service_resource_claims SET state='retired'
             WHERE id='33333333-3333-4333-8333-333333333333'"
        );
        self::denied($this->admin,
            "UPDATE public.portal_service_projects
             SET source_claim_id='33333333-3333-4333-8333-333333333333'");
        self::assertNull($this->state()['source_claim_id']);
        self::assertSame(1,(int)$this->state()['project_revision']);
    }

    public function test_cannot_duplicate_a_source_claim_for_two_projects(): void
    {
        $this->createProject();
        $this->createProject(self::PROJECT_B,self::ORG_A,'FIXTURE-PROJECT-2');
        $this->bind();
        self::denied($this->admin,
            "UPDATE public.portal_service_projects
             SET source_claim_id=CAST(? AS uuid)
             WHERE id=CAST(? AS uuid)",
            [self::CLAIM_A,self::PROJECT_B],'23505',
        );
        self::assertNull($this->state(self::PROJECT_B)['source_claim_id']);
    }

    public function test_same_reference_can_exist_in_another_tenant_but_not_twice_inside_one(): void
    {
        $this->createProject();
        $this->createProject(self::PROJECT_B,self::ORG_B,'FIXTURE-PROJECT-1');
        self::assertSame(self::ORG_B,$this->state(self::PROJECT_B)['organization_id']);
        self::denied($this->admin,
            'INSERT INTO public.portal_service_projects
               (id,organization_id,project_ref,product_code)
             VALUES(CAST(? AS uuid),CAST(? AS uuid),?,?)',
            [self::PROJECT_C,self::ORG_A,'FIXTURE-PROJECT-1','sites_landing_page'],
            '23505',
        );
    }

    public function test_unapproved_products_or_states_never_entitle_crm_university_or_ads(): void
    {
        foreach (['imagine_crm','universidade_imagine','site_multipage',''] as $code) {
            self::denied($this->admin,
                'INSERT INTO public.portal_service_projects
                   (id,organization_id,project_ref,product_code)
                 VALUES(gen_random_uuid(),CAST(? AS uuid),?,?)',
                [self::ORG_A,'FIXTURE-PROJECT-'.strlen($code),$code],'23514',
            );
        }
        self::denied($this->admin,
            "INSERT INTO public.portal_service_projects
               (id,organization_id,project_ref,product_code,state)
             VALUES(gen_random_uuid(),CAST(? AS uuid),'UNAUTHORIZED','sites_onepage','active')",
            [self::ORG_A],
        );
        $this->createProject();
        self::denied($this->admin,
            "UPDATE public.portal_service_projects SET state='active'");
        self::assertSame('draft',$this->state()['state']);
    }

    public function test_inactive_organization_cannot_create_new_project(): void
    {
        $this->admin->exec(
            "UPDATE public.portal_organizations SET status='suspended'
             WHERE id='11111111-1111-4111-8111-111111111111'"
        );
        self::denied($this->admin,
            "INSERT INTO public.portal_service_projects
               (id,organization_id,project_ref,product_code)
             VALUES(gen_random_uuid(),CAST(? AS uuid),'NO-ORG','sites_onepage')",
            [self::ORG_A],
        );
        self::assertSame(0,(int)$this->admin->query(
            'SELECT count(*) FROM public.portal_service_projects'
        )->fetchColumn());
    }

    public function test_draft_can_be_retired_without_supplying_source_or_allowing_resurrection(): void
    {
        $this->createProject();
        $this->admin->exec("UPDATE public.portal_service_projects SET state='retired'");
        self::assertSame(2,(int)$this->state()['project_revision']);
        self::assertSame('retired',$this->state()['state']);
        self::denied($this->admin,
            "UPDATE public.portal_service_projects SET state='review'");
        self::denied($this->admin,
            "UPDATE public.portal_service_projects SET state='draft'");
        self::denied($this->admin,
            "DELETE FROM public.portal_service_projects");
    }
}
