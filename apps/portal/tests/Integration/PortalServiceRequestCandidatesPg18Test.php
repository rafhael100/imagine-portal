<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PDOException;
use Tests\TestCase;

/**
 * Disposable PG18 only: prove a complete synthetic service request
 * state machine and automatically recorded transitions, NOT an API.
 */
final class PortalServiceRequestCandidatesPg18Test extends TestCase
{
    private const ORG='11111111-1111-4111-8111-111111111111';
    private const FOREIGN='22222222-2222-4222-8222-222222222222';
    private const PROJECT='33333333-3333-4333-8333-333333333333';
    private const REQUEST='44444444-4444-4444-8444-444444444444';
    private const SECOND='55555555-5555-4555-8555-555555555555';

    private PDO $admin;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('APP_ENV')!=='testing'
            || getenv('PORTAL_BROWSER_PG_LAB')!=='DISPOSABLE_PG18'
            || getenv('PGHOST')!=='127.0.0.1'
            || getenv('PGPORT')!=='5432'
            || getenv('PGDATABASE')!=='portal_browser_lab') {
            self::fail('Disposable PostgreSQL 18 Portal request lab required.');
        }
        $this->admin=self::connect('postgres',(string)getenv('PGPASSWORD'));
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
 public.portal_organizations,public.portal_auth_attempt_buckets CASCADE
SQL);
        $this->admin->exec(<<<'SQL'
INSERT INTO public.portal_organizations(id,reference,display_name,status)
VALUES ('11111111-1111-4111-8111-111111111111','requests-org-a','Requests A','active'),
       ('22222222-2222-4222-8222-222222222222','requests-org-b','Requests B','active');
INSERT INTO public.portal_service_projects(id,organization_id,project_ref,product_code)
VALUES ('33333333-3333-4333-8333-333333333333',
        '11111111-1111-4111-8111-111111111111',
        'DEMO-ONEPAGE','sites_onepage');
SQL);
    }

    private static function connect(string $user,string $password): PDO
    {
        return new PDO('pgsql:host=127.0.0.1;port=5432;dbname=portal_browser_lab',
            $user,$password,[
                PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES=>false,
            ]);
    }

    private static function denied(PDO $db,string $sql,array $args=[],
                                   string $state='42501'): void
    {
        try {
            $stmt=$db->prepare($sql);
            $stmt->execute($args);
        } catch (PDOException $e) {
            self::assertSame($state,(string)$e->getCode());
            return;
        }
        self::fail('Unsafe Portal service request operation was accepted.');
    }

    private function create(string $id=self::REQUEST,string $ref='DEMO-REQ-1',
                            string $org=self::ORG,string $project=self::PROJECT): void
    {
        $s=$this->admin->prepare(
            'INSERT INTO public.portal_service_requests
               (id,organization_id,project_id,request_ref)
             VALUES(CAST(? AS uuid),CAST(? AS uuid),CAST(? AS uuid),?)'
        );
        $s->execute([$id,$org,$project,$ref]);
    }

    private function advance(string $state,string $id=self::REQUEST): void
    {
        $s=$this->admin->prepare(
            'UPDATE public.portal_service_requests SET status=?
             WHERE id=CAST(? AS uuid)'
        );
        $s->execute([$state,$id]);
    }

    /** @return array<string,mixed> */
    private function row(string $id=self::REQUEST): array
    {
        $s=$this->admin->prepare(
            'SELECT organization_id,project_id,request_ref,status,request_revision
               FROM public.portal_service_requests WHERE id=CAST(? AS uuid)'
        );
        $s->execute([$id]);
        $r=$s->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($r);
        return $r;
    }

    /** @return list<array<string,mixed>> */
    private function events(): array
    {
        return $this->admin->query(
            'SELECT from_status,to_status,request_revision,organization_id
               FROM public.portal_service_request_transitions
              ORDER BY request_revision'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function test_draft_insert_is_atomic_and_records_event_revision_one(): void
    {
        $this->create();
        $r=$this->row();
        self::assertSame('draft',$r['status']);
        self::assertSame(1,(int)$r['request_revision']);
        self::assertSame(self::ORG,$r['organization_id']);
        self::assertSame(self::PROJECT,$r['project_id']);
        $events=$this->events();
        self::assertCount(1,$events);
        self::assertNull($events[0]['from_status']);
        self::assertSame('draft',$events[0]['to_status']);
        self::assertSame(1,(int)$events[0]['request_revision']);
        self::assertSame(self::ORG,$events[0]['organization_id']);
    }

    public function test_all_seven_business_transitions_append_exact_sequential_history(): void
    {
        $this->create();
        $states=[
            'submitted','in_review','waiting_on_customer','in_review',
            'resolved','in_review','resolved','closed',
        ];
        $prior='draft';
        foreach ($states as $i=>$next) {
            $this->advance($next);
            $r=$this->row();
            self::assertSame($next,$r['status']);
            self::assertSame($i+2,(int)$r['request_revision']);
            $events=$this->events();
            self::assertCount($i+2,$events);
            self::assertSame($prior,$events[$i+1]['from_status']);
            self::assertSame($next,$events[$i+1]['to_status']);
            self::assertSame($i+2,(int)$events[$i+1]['request_revision']);
            $prior=$next;
        }
        self::assertSame('closed',$this->row()['status']);
        self::denied($this->admin,
            "UPDATE public.portal_service_requests SET status='in_review'");
        self::assertCount(9,$this->events());
    }

    public function test_out_of_order_noop_and_closed_reopen_are_not_transitions(): void
    {
        $this->create();
        foreach ([
            "UPDATE public.portal_service_requests SET status='resolved'",
            "UPDATE public.portal_service_requests SET status='in_review'",
            "UPDATE public.portal_service_requests SET status='closed'",
            "UPDATE public.portal_service_requests SET status='draft'",
            "UPDATE public.portal_service_requests SET status='active'",
        ] as $sql) {
            self::denied($this->admin,$sql);
        }
        self::assertSame(1,(int)$this->row()['request_revision']);
        self::assertCount(1,$this->events());
    }

    public function test_request_id_tenant_project_reference_revision_are_immutable(): void
    {
        $this->create();
        foreach ([
            "UPDATE public.portal_service_requests
                SET organization_id='22222222-2222-4222-8222-222222222222'",
            "UPDATE public.portal_service_requests
                SET project_id='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'",
            "UPDATE public.portal_service_requests SET request_ref='STOLEN-REF'",
            "UPDATE public.portal_service_requests
                SET id='bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'",
            "UPDATE public.portal_service_requests SET request_revision=1000",
            "UPDATE public.portal_service_requests
                SET updated_at=clock_timestamp()+interval '1 minute'",
        ] as $sql) {
            self::denied($this->admin,$sql);
        }
        $this->admin->exec("UPDATE public.portal_service_requests SET status='submitted'");
        self::assertSame(2,(int)$this->row()['request_revision']);
        self::assertCount(2,$this->events());
    }

    public function test_manual_event_insert_update_or_delete_cannot_forge_transition_history(): void
    {
        $this->create();
        self::denied($this->admin,
            'INSERT INTO public.portal_service_request_transitions
                 (event_id,request_id,organization_id,to_status,request_revision)
             VALUES(gen_random_uuid(),CAST(? AS uuid),CAST(? AS uuid),?,2)',
            [self::REQUEST,self::ORG,'resolved']);
        self::denied($this->admin,
            "UPDATE public.portal_service_request_transitions SET to_status='resolved'");
        self::denied($this->admin,
            'DELETE FROM public.portal_service_request_transitions');
        self::assertSame('draft',$this->events()[0]['to_status']);
        self::assertCount(1,$this->events());
    }

    public function test_browser_role_cannot_select_insert_or_update_requests_or_events(): void
    {
        $this->create();
        foreach (['portal_service_requests','portal_service_request_transitions'] as $table) {
            $flags=$this->admin->query(
                "SELECT relrowsecurity,relforcerowsecurity FROM pg_class
                 WHERE oid='public.".$table."'::regclass"
            )->fetch(PDO::FETCH_ASSOC);
            self::assertContains($flags['relrowsecurity'],['t',true,'1',1]);
            self::assertContains($flags['relforcerowsecurity'],['t',true,'1',1]);
            self::assertSame(0,(int)$this->admin->query(
                "SELECT count(*) FROM pg_policies WHERE schemaname='public'
                  AND tablename='".$table."'"
            )->fetchColumn());
        }
        $runtime=self::connect('portal_browser_runtime',
            (string)getenv('PORTAL_BROWSER_RUNTIME_PASSWORD'));
        self::denied($runtime,'SELECT * FROM public.portal_service_requests');
        self::denied($runtime,'SELECT * FROM public.portal_service_request_transitions');
        self::denied($runtime,
            "UPDATE public.portal_service_requests SET status='submitted'");
        self::denied($runtime,
            'DELETE FROM public.portal_service_request_transitions');
    }

    public function test_foreign_tenant_missing_project_retired_project_and_suspension_block_new_requests(): void
    {
        self::denied($this->admin,
            'INSERT INTO public.portal_service_requests
                (id,organization_id,project_id,request_ref)
             VALUES(gen_random_uuid(),CAST(? AS uuid),CAST(? AS uuid),?)',
            [self::FOREIGN,self::PROJECT,'FOREIGN-REQ']);
        self::denied($this->admin,
            'INSERT INTO public.portal_service_requests
                (id,organization_id,project_id,request_ref)
             VALUES(gen_random_uuid(),CAST(? AS uuid),CAST(? AS uuid),?)',
            [self::ORG,'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa','MISSING']);
        $this->admin->exec(
            "UPDATE public.portal_service_projects SET state='retired'
             WHERE id='33333333-3333-4333-8333-333333333333'"
        );
        self::denied($this->admin,
            'INSERT INTO public.portal_service_requests
                (id,organization_id,project_id,request_ref)
             VALUES(gen_random_uuid(),CAST(? AS uuid),CAST(? AS uuid),?)',
            [self::ORG,self::PROJECT,'RETIRED']);
        self::assertSame(0,(int)$this->admin->query(
            'SELECT count(*) FROM public.portal_service_requests'
        )->fetchColumn());
    }

    public function test_project_retirement_or_tenant_suspension_freezes_existing_request(): void
    {
        $this->create();
        $this->admin->exec(
            "UPDATE public.portal_service_projects SET state='retired'
             WHERE id='33333333-3333-4333-8333-333333333333'"
        );
        self::denied($this->admin,
            "UPDATE public.portal_service_requests SET status='submitted'");
        self::assertSame('draft',$this->row()['status']);
        self::assertCount(1,$this->events());
    }

    public function test_duplicate_tenant_reference_and_forbidden_initial_state_are_denied(): void
    {
        $this->create();
        self::denied($this->admin,
            'INSERT INTO public.portal_service_requests
                (id,organization_id,project_id,request_ref)
             VALUES(CAST(? AS uuid),CAST(? AS uuid),CAST(? AS uuid),?)',
            [self::SECOND,self::ORG,self::PROJECT,'DEMO-REQ-1'],'23505');
        self::denied($this->admin,
            "INSERT INTO public.portal_service_requests
                (id,organization_id,project_id,request_ref,status)
             VALUES(gen_random_uuid(),CAST(? AS uuid),CAST(? AS uuid),'NOT-DRAFT','submitted')",
            [self::ORG,self::PROJECT]);
        self::assertCount(1,$this->events());
    }

    public function test_concurrent_row_lock_does_not_allow_unlogged_update(): void
    {
        $this->create();
        $this->admin->beginTransaction();
        try {
            $this->admin->exec(
                "UPDATE public.portal_service_requests SET status='submitted'"
            );
            $second=self::connect('postgres',(string)getenv('PGPASSWORD'));
            $second->exec("SET lock_timeout TO '180ms'");
            self::denied($second,
                "UPDATE public.portal_service_requests SET status='submitted'",
                [],'55P03');
        } finally {
            $this->admin->rollBack();
        }
        self::assertSame('draft',$this->row()['status']);
        self::assertCount(1,$this->events());
        $this->advance('submitted');
        self::assertCount(2,$this->events());
    }
}
