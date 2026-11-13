<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PDOException;
use Tests\TestCase;

/** Real PostgreSQL 18, but exclusively synthetic disposable CI records. */
final class PortalServiceProjectCheckpointsPg18Test extends TestCase
{
    private const ORG_A='11111111-1111-4111-8111-111111111111';
    private const ORG_B='22222222-2222-4222-8222-222222222222';
    private const CLAIM_ONE='33333333-3333-4333-8333-333333333333';
    private const CLAIM_LANDING='44444444-4444-4444-8444-444444444444';
    private const CLAIM_PERF='55555555-5555-4555-8555-555555555555';
    private const PROJECT_ONE='66666666-6666-4666-8666-666666666666';
    private const PROJECT_LANDING='77777777-7777-4777-8777-777777777777';
    private const PROJECT_PERF='88888888-8888-4888-8888-888888888888';
    private const CHECK_ONE='99999999-9999-4999-8999-999999999999';
    private const CHECK_TWO='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const CHECK_THREE='bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
    private const CHECK_FOUR='cccccccc-cccc-4ccc-8ccc-cccccccccccc';

    private PDO $admin;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('APP_ENV')!=='testing'
            || getenv('PORTAL_BROWSER_PG_LAB')!=='DISPOSABLE_PG18'
            || getenv('PGHOST')!=='127.0.0.1'
            || getenv('PGPORT')!=='5432'
            || getenv('PGDATABASE')!=='portal_browser_lab') {
            self::fail('Disposable PG18 service checkpoint laboratory required.');
        }
        $this->admin=self::connect('postgres',(string)getenv('PGPASSWORD'));
        self::assertSame('portal_browser_lab',$this->admin->query(
            'SELECT current_database()'
        )->fetchColumn());
        self::assertSame(18,(int)$this->admin->query(
            "SELECT current_setting('server_version_num')::integer/10000"
        )->fetchColumn());

        $this->admin->exec(<<<'SQL'
TRUNCATE public.portal_service_project_checkpoints,
 public.portal_service_projects,public.portal_service_resource_claims,
 public.portal_email_proof_challenges,public.portal_auth_sessions,
 public.portal_membership_grants,public.portal_memberships,public.portal_identities,
 public.portal_organizations,public.portal_auth_attempt_buckets CASCADE
SQL);
        $this->admin->exec(<<<'SQL'
INSERT INTO public.portal_organizations(id,reference,display_name,status)
VALUES('11111111-1111-4111-8111-111111111111','check-org-a','Check A','active'),
      ('22222222-2222-4222-8222-222222222222','check-org-b','Check B','active')
SQL);
        $this->admin->exec(<<<'SQL'
INSERT INTO public.portal_service_resource_claims
 (id,organization_id,source_system,source_resource_ref,product_code)
VALUES
 ('33333333-3333-4333-8333-333333333333',
  '11111111-1111-4111-8111-111111111111',
  'imagine_sites','ONEPAGE-FAKE','sites_onepage'),
 ('44444444-4444-4444-8444-444444444444',
  '11111111-1111-4111-8111-111111111111',
  'imagine_sites','LANDING-FAKE','sites_landing_page'),
 ('55555555-5555-4555-8555-555555555555',
  '11111111-1111-4111-8111-111111111111',
  'imagine_performance','PERFORMANCE-FAKE','performance_ai')
SQL);
    }

    private static function connect(string $user,string $pass): PDO
    {
        return new PDO(
            'pgsql:host=127.0.0.1;port=5432;dbname=portal_browser_lab',
            $user,$pass,[
                PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES=>false,
            ],
        );
    }

    private static function denied(PDO $db,string $sql,array $parameters=[],
                                   string $state='42501'): void
    {
        try {
            $stmt=$db->prepare($sql);
            $stmt->execute($parameters);
        } catch (PDOException $error) {
            self::assertSame($state,(string)$error->getCode());
            return;
        }
        self::fail('Unsafe checkpoint mutation was accepted.');
    }

    private function project(string $id=self::PROJECT_ONE,string $code='sites_onepage',
                             string $claim=self::CLAIM_ONE): void
    {
        $create=$this->admin->prepare(
            'INSERT INTO public.portal_service_projects
               (id,organization_id,project_ref,product_code)
             VALUES(CAST(? AS uuid),CAST(? AS uuid),?,?)'
        );
        $create->execute([$id,self::ORG_A,'PROJECT-'.substr($id,0,8),$code]);
        $attach=$this->admin->prepare(
            'UPDATE public.portal_service_projects
             SET source_claim_id=CAST(? AS uuid) WHERE id=CAST(? AS uuid)'
        );
        $attach->execute([$claim,$id]);
    }

    private function checkpoint(string $id,string $project,string $code): void
    {
        $stmt=$this->admin->prepare(
            'INSERT INTO public.portal_service_project_checkpoints
             (id,project_id,organization_id,checkpoint_code)
             VALUES(CAST(? AS uuid),CAST(? AS uuid),CAST(? AS uuid),?)'
        );
        $stmt->execute([$id,$project,self::ORG_A,$code]);
    }

    private function reviewCheckpoint(string $id): void
    {
        $stmt=$this->admin->prepare(
            "UPDATE public.portal_service_project_checkpoints
             SET state='reviewed' WHERE id=CAST(? AS uuid)"
        );
        $stmt->execute([$id]);
    }

    private function reviewProject(string $id): void
    {
        $stmt=$this->admin->prepare(
            "UPDATE public.portal_service_projects SET state='review'
             WHERE id=CAST(? AS uuid)"
        );
        $stmt->execute([$id]);
    }

    /** @return array<string,mixed> */
    private function state(string $id=self::CHECK_ONE): array
    {
        $q=$this->admin->prepare(
            'SELECT project_id,organization_id,checkpoint_code,state,
                    checkpoint_revision FROM public.portal_service_project_checkpoints
             WHERE id=CAST(? AS uuid)'
        );
        $q->execute([$id]);
        $data=$q->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($data);
        return $data;
    }

    public function test_default_deny_rls_and_no_application_permissions_even_for_known_ids(): void
    {
        $this->project();
        $this->checkpoint(self::CHECK_ONE,self::PROJECT_ONE,'briefing');
        $flags=$this->admin->query(
            "SELECT relrowsecurity,relforcerowsecurity FROM pg_class
             WHERE oid='public.portal_service_project_checkpoints'::regclass"
        )->fetch(PDO::FETCH_ASSOC);
        self::assertContains($flags['relrowsecurity'],['t',true,'1',1]);
        self::assertContains($flags['relforcerowsecurity'],['t',true,'1',1]);
        self::assertSame(0,(int)$this->admin->query(
            "SELECT count(*) FROM pg_policies WHERE schemaname='public'
             AND tablename='portal_service_project_checkpoints'"
        )->fetchColumn());

        $runtime=self::connect('portal_browser_runtime',
            (string)getenv('PORTAL_BROWSER_RUNTIME_PASSWORD'));
        self::denied($runtime,'SELECT * FROM public.portal_service_project_checkpoints');
        self::denied($runtime,
            "UPDATE public.portal_service_project_checkpoints SET state='reviewed'");
        self::denied($runtime,
            "INSERT INTO public.portal_service_project_checkpoints
               (id,project_id,organization_id,checkpoint_code)
             VALUES(gen_random_uuid(),CAST(? AS uuid),CAST(? AS uuid),'briefing')",
            [self::PROJECT_ONE,self::ORG_A]);
    }

    public function test_review_requires_every_onepage_checkpoint_reviewed_and_bound_project(): void
    {
        $this->project();
        $this->checkpoint(self::CHECK_ONE,self::PROJECT_ONE,'briefing');
        $this->checkpoint(self::CHECK_TWO,self::PROJECT_ONE,'content');
        self::denied($this->admin,
            "UPDATE public.portal_service_projects SET state='review'");
        $this->checkpoint(self::CHECK_THREE,self::PROJECT_ONE,'domain');
        self::denied($this->admin,
            "UPDATE public.portal_service_projects SET state='review'");

        foreach ([self::CHECK_ONE,self::CHECK_TWO,self::CHECK_THREE] as $id) {
            $this->reviewCheckpoint($id);
            self::assertSame('reviewed',$this->state($id)['state']);
            self::assertSame(2,(int)$this->state($id)['checkpoint_revision']);
        }
        $this->reviewProject(self::PROJECT_ONE);
        self::assertSame('review',$this->admin->query(
            "SELECT state FROM public.portal_service_projects
             WHERE id='66666666-6666-4666-8666-666666666666'"
        )->fetchColumn());
        self::denied($this->admin,
            "UPDATE public.portal_service_project_checkpoints
             SET state='pending'");
        self::denied($this->admin,
            "DELETE FROM public.portal_service_project_checkpoints");
    }

    public function test_landing_requires_consent_and_performance_requires_all_four_items(): void
    {
        $this->project(self::PROJECT_LANDING,'sites_landing_page',self::CLAIM_LANDING);
        $this->project(self::PROJECT_PERF,'performance_ai',self::CLAIM_PERF);
        $landing=[
            [self::CHECK_ONE,'briefing'],[self::CHECK_TWO,'content'],
            [self::CHECK_THREE,'consent'],
        ];
        foreach ($landing as [$id,$code]) {
            $this->checkpoint($id,self::PROJECT_LANDING,$code);
            $this->reviewCheckpoint($id);
        }
        $this->reviewProject(self::PROJECT_LANDING);
        self::denied($this->admin,
            "INSERT INTO public.portal_service_project_checkpoints
                (id,project_id,organization_id,checkpoint_code)
             VALUES(gen_random_uuid(),CAST(? AS uuid),CAST(? AS uuid),'domain')",
            [self::PROJECT_LANDING,self::ORG_A]);

        foreach ([
            [self::CHECK_ONE,'goals'],[self::CHECK_TWO,'ad_accounts'],
            [self::CHECK_THREE,'creatives'],
        ] as [$id,$code]) {
            // Previously used UUIDs are globally unique: separate identities.
            $newId=match ($id) {
                self::CHECK_ONE => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
                self::CHECK_TWO => 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee',
                default => 'ffffffff-ffff-4fff-8fff-ffffffffffff',
            };
            $this->checkpoint($newId,self::PROJECT_PERF,$code);
            $this->reviewCheckpoint($newId);
        }
        self::denied($this->admin,
            "UPDATE public.portal_service_projects
             SET state='review' WHERE id=CAST(? AS uuid)",
            [self::PROJECT_PERF]);
        $this->checkpoint(self::CHECK_FOUR,self::PROJECT_PERF,'budget');
        $this->reviewCheckpoint(self::CHECK_FOUR);
        $this->reviewProject(self::PROJECT_PERF);
        self::assertSame('review',$this->admin->query(
            "SELECT state FROM public.portal_service_projects
             WHERE id='88888888-8888-4888-8888-888888888888'"
        )->fetchColumn());
    }

    public function test_wrong_product_and_foreign_tenant_checkpoints_are_refused(): void
    {
        $this->project();
        foreach (['consent','ad_accounts','budget','sso'] as $code) {
            self::denied($this->admin,
                'INSERT INTO public.portal_service_project_checkpoints
                   (id,project_id,organization_id,checkpoint_code)
                 VALUES(gen_random_uuid(),CAST(? AS uuid),CAST(? AS uuid),?)',
                [self::PROJECT_ONE,self::ORG_A,$code]);
        }
        self::denied($this->admin,
            'INSERT INTO public.portal_service_project_checkpoints
               (id,project_id,organization_id,checkpoint_code)
             VALUES(gen_random_uuid(),CAST(? AS uuid),CAST(? AS uuid),?)',
            [self::PROJECT_ONE,self::ORG_B,'briefing']);
        self::denied($this->admin,
            'INSERT INTO public.portal_service_project_checkpoints
               (id,project_id,organization_id,checkpoint_code)
             VALUES(gen_random_uuid(),CAST(? AS uuid),CAST(? AS uuid),?)',
            ['99999999-9999-4999-8999-111111111111',self::ORG_A,'briefing']);
    }

    public function test_checkpoint_scope_version_and_completed_state_are_immutable(): void
    {
        $this->project();
        $this->checkpoint(self::CHECK_ONE,self::PROJECT_ONE,'briefing');
        foreach ([
            "UPDATE public.portal_service_project_checkpoints SET organization_id='22222222-2222-4222-8222-222222222222'",
            "UPDATE public.portal_service_project_checkpoints SET checkpoint_code='domain'",
            "UPDATE public.portal_service_project_checkpoints SET checkpoint_revision=10",
            "UPDATE public.portal_service_project_checkpoints SET project_id='77777777-7777-4777-8777-777777777777'",
            "UPDATE public.portal_service_project_checkpoints SET id='ffffffff-ffff-4fff-8fff-ffffffffffff'",
            "UPDATE public.portal_service_project_checkpoints
             SET updated_at=clock_timestamp()+interval '1 minute'",
        ] as $sql) {
            self::denied($this->admin,$sql);
        }
        $this->reviewCheckpoint(self::CHECK_ONE);
        self::denied($this->admin,
            "UPDATE public.portal_service_project_checkpoints SET state='pending'");
        self::denied($this->admin,
            "UPDATE public.portal_service_project_checkpoints SET state='reviewed'");
        self::denied($this->admin,
            'DELETE FROM public.portal_service_project_checkpoints');
        self::assertSame(2,(int)$this->state()['checkpoint_revision']);
    }

    public function test_duplicate_checkpoint_or_direct_reviewed_insert_is_denied(): void
    {
        $this->project();
        $this->checkpoint(self::CHECK_ONE,self::PROJECT_ONE,'briefing');
        self::denied($this->admin,
            'INSERT INTO public.portal_service_project_checkpoints
               (id,project_id,organization_id,checkpoint_code)
             VALUES(CAST(? AS uuid),CAST(? AS uuid),CAST(? AS uuid),?)',
            [self::CHECK_TWO,self::PROJECT_ONE,self::ORG_A,'briefing'],
            '23505');
        self::denied($this->admin,
            "INSERT INTO public.portal_service_project_checkpoints
               (id,project_id,organization_id,checkpoint_code,state)
             VALUES(gen_random_uuid(),CAST(? AS uuid),CAST(? AS uuid),'content','reviewed')",
            [self::PROJECT_ONE,self::ORG_A]);
        self::denied($this->admin,
            "INSERT INTO public.portal_service_project_checkpoints
               (id,project_id,organization_id,checkpoint_code,checkpoint_revision)
             VALUES(gen_random_uuid(),CAST(? AS uuid),CAST(? AS uuid),'domain',2)",
            [self::PROJECT_ONE,self::ORG_A]);
    }

    public function test_project_retirement_blocks_new_or_modified_checkpoints(): void
    {
        $this->project();
        $this->checkpoint(self::CHECK_ONE,self::PROJECT_ONE,'briefing');
        $this->admin->exec(
            "UPDATE public.portal_service_projects SET state='retired'
             WHERE id='66666666-6666-4666-8666-666666666666'"
        );
        self::denied($this->admin,
            "UPDATE public.portal_service_project_checkpoints SET state='reviewed'");
        self::denied($this->admin,
            "INSERT INTO public.portal_service_project_checkpoints
               (id,project_id,organization_id,checkpoint_code)
             VALUES(gen_random_uuid(),CAST(? AS uuid),CAST(? AS uuid),'content')",
            [self::PROJECT_ONE,self::ORG_A]);
    }

    public function test_concurrent_project_row_lock_prevents_checkpoint_insertion(): void
    {
        $this->project();
        $this->admin->beginTransaction();
        try {
            $this->admin->exec(
                "UPDATE public.portal_service_projects SET state='draft'
                 WHERE id='66666666-6666-4666-8666-666666666666'"
            );
            $competing=self::connect('postgres',(string)getenv('PGPASSWORD'));
            $competing->exec("SET lock_timeout TO '180ms'");
            self::denied($competing,
                'INSERT INTO public.portal_service_project_checkpoints
                   (id,project_id,organization_id,checkpoint_code)
                 VALUES(CAST(? AS uuid),CAST(? AS uuid),CAST(? AS uuid),?)',
                [self::CHECK_ONE,self::PROJECT_ONE,self::ORG_A,'briefing'],
                '55P03');
        } finally {
            $this->admin->rollBack();
        }
        $this->checkpoint(self::CHECK_ONE,self::PROJECT_ONE,'briefing');
        self::assertSame('pending',$this->state()['state']);
    }
    public function test_retired_source_claim_blocks_review_even_after_checklist_is_complete(): void
    {
        $this->project();
        foreach ([
            [self::CHECK_ONE,'briefing'],
            [self::CHECK_TWO,'content'],
            [self::CHECK_THREE,'domain'],
        ] as [$id,$code]) {
            $this->checkpoint($id,self::PROJECT_ONE,$code);
            $this->reviewCheckpoint($id);
        }
        // Race-sensitive condition: claim was a candidate when bound but
        // is retired before the project review transaction starts.
        $this->admin->exec(
            "UPDATE public.portal_service_resource_claims
                SET state='retired'
              WHERE id='33333333-3333-4333-8333-333333333333'"
        );
        self::denied($this->admin,
            "UPDATE public.portal_service_projects
                SET state='review'
              WHERE id='66666666-6666-4666-8666-666666666666'");
        self::assertSame('draft',$this->admin->query(
            "SELECT state FROM public.portal_service_projects
              WHERE id='66666666-6666-4666-8666-666666666666'"
        )->fetchColumn());
    }

    public function test_suspended_tenant_blocks_review_of_otherwise_complete_checklist(): void
    {
        $this->project();
        foreach ([
            [self::CHECK_ONE,'briefing'],
            [self::CHECK_TWO,'content'],
            [self::CHECK_THREE,'domain'],
        ] as [$id,$code]) {
            $this->checkpoint($id,self::PROJECT_ONE,$code);
            $this->reviewCheckpoint($id);
        }
        $this->admin->exec(
            "UPDATE public.portal_organizations
                SET status='suspended'
              WHERE id='11111111-1111-4111-8111-111111111111'"
        );
        self::denied($this->admin,
            "UPDATE public.portal_service_projects
                SET state='review'
              WHERE id='66666666-6666-4666-8666-666666666666'");
        self::assertSame('draft',$this->admin->query(
            "SELECT state FROM public.portal_service_projects
              WHERE id='66666666-6666-4666-8666-666666666666'"
        )->fetchColumn());
    }

}
