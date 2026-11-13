<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Organizations\OrganizationId;
use App\Domain\ServiceRequests\ServiceRequestStatus;
use PDO;
use Tests\Support\DisposablePg18ProjectInboxReader;
use Tests\TestCase;

/**
 * Tamper tests require superuser ONLY in disposable PostgreSQL CI.
 * The operational application role retains zero table permissions.
 */
final class PortalPg18RequestHistoryReconciliationTest extends TestCase
{
    private const ORG='11111111-1111-4111-8111-111111111111';
    private const OTHER='22222222-2222-4222-8222-222222222222';
    private const PROJECT='33333333-3333-4333-8333-333333333333';
    private const REQUEST='44444444-4444-4444-8444-444444444444';
    private PDO $db;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('APP_ENV') !== 'testing'
            || getenv('PORTAL_BROWSER_PG_LAB') !== 'DISPOSABLE_PG18'
            || getenv('PGHOST') !== '127.0.0.1'
            || getenv('PGPORT') !== '5432'
            || getenv('PGDATABASE') !== 'portal_browser_lab') {
            self::fail('Synthetic PG18 history laboratory required.');
        }
        $this->db=new PDO(
            'pgsql:host=127.0.0.1;port=5432;dbname=portal_browser_lab',
            'postgres',(string)getenv('PGPASSWORD'),[
                PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES=>false,
            ],
        );
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
VALUES ('11111111-1111-4111-8111-111111111111','history-org-a','History A','active'),
       ('22222222-2222-4222-8222-222222222222','history-org-b','History B','active');
INSERT INTO public.portal_service_projects(id,organization_id,project_ref,product_code)
VALUES ('33333333-3333-4333-8333-333333333333',
        '11111111-1111-4111-8111-111111111111',
        'DEMO-SVC-HISTORY','sites_onepage');
INSERT INTO public.portal_service_requests(id,organization_id,project_id,request_ref)
VALUES ('44444444-4444-4444-8444-444444444444',
        '11111111-1111-4111-8111-111111111111',
        '33333333-3333-4333-8333-333333333333','DEMO-REQ-HISTORY');
UPDATE public.portal_service_requests SET status='submitted';
UPDATE public.portal_service_requests SET status='in_review';
SQL);
    }

    private function read(): \App\Domain\ServiceRequests\RequestInboxPreviewResult
    {
        return (new DisposablePg18ProjectInboxReader($this->db))
            ->project(new OrganizationId(self::ORG),self::PROJECT);
    }

    private function tamper(string $sql, bool $requestTable=false): void
    {
        $table=$requestTable ? 'portal_service_requests' : 'portal_service_request_transitions';
        $guard=$requestTable ? 'portal_service_request_guard' : 'portal_service_request_event_guard';
        $this->db->exec('ALTER TABLE public.'.$table.' DISABLE TRIGGER '.$guard);
        try {
            $this->db->exec($sql);
        } finally {
            $this->db->exec('ALTER TABLE public.'.$table.' ENABLE TRIGGER '.$guard);
        }
    }

    private function assertNoRowsAfterTampering(): void
    {
        $result=$this->read();
        self::assertSame('invalid_preview_input',$result->reason);
        self::assertSame([],$result->items);
        self::assertSame(0,$result->total);
        self::assertNull($result->nextOffset);
        self::assertFalse($result->mayExposeCustomerData());
        self::assertFalse($this->db->inTransaction());
    }

    public function test_valid_history_is_complete_and_projects_current_status(): void
    {
        $result=$this->read();
        self::assertTrue($result->isPreparedPreview());
        self::assertSame(1,$result->total);
        self::assertCount(1,$result->items);
        self::assertSame(ServiceRequestStatus::InReview,$result->items[0]->status);
        self::assertSame(3,$result->items[0]->revision);
        self::assertFalse($result->mayExposeCustomerData());
        self::assertSame(3,(int)$this->db->query(
            "SELECT count(*) FROM public.portal_service_request_transitions"
        )->fetchColumn());
    }

    public function test_removed_middle_audit_event_hides_entire_inbox(): void
    {
        $this->tamper(
            "DELETE FROM public.portal_service_request_transitions
              WHERE request_id='".self::REQUEST."' AND request_revision=2"
        );
        $this->assertNoRowsAfterTampering();
    }

    public function test_reordered_revision_chain_hides_entire_inbox(): void
    {
        $this->tamper(
            "UPDATE public.portal_service_request_transitions
                SET request_revision=5
              WHERE request_id='".self::REQUEST."' AND request_revision=2"
        );
        $this->assertNoRowsAfterTampering();
    }

    public function test_invalid_business_transition_hides_entire_inbox(): void
    {
        $this->tamper(
            "UPDATE public.portal_service_request_transitions
                SET to_status='closed'
              WHERE request_id='".self::REQUEST."' AND request_revision=2"
        );
        $this->assertNoRowsAfterTampering();
    }

    public function test_foreign_tenant_event_hides_entire_inbox(): void
    {
        $this->tamper(
            "UPDATE public.portal_service_request_transitions
                SET organization_id='".self::OTHER."'
              WHERE request_id='".self::REQUEST."' AND request_revision=3"
        );
        $this->assertNoRowsAfterTampering();
    }

    public function test_current_state_changed_without_corresponding_audit_is_rejected(): void
    {
        $this->tamper(
            "UPDATE public.portal_service_requests
                SET status='resolved'
              WHERE id='".self::REQUEST."'",
            requestTable:true,
        );
        $this->assertNoRowsAfterTampering();
    }

    public function test_extra_event_without_corresponding_revision_is_rejected(): void
    {
        $this->tamper(
            "INSERT INTO public.portal_service_request_transitions
                (event_id,request_id,organization_id,from_status,to_status,request_revision)
             VALUES(gen_random_uuid(),'".self::REQUEST."','".self::ORG."',
                    'in_review','resolved',4)"
        );
        $this->assertNoRowsAfterTampering();
    }
}
