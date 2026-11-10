<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Organizations\OrganizationId;
use App\Domain\ServiceRequests\DemoRequestCommand;
use App\Domain\ServiceRequests\ServiceRequestAction as Action;
use App\Domain\ServiceRequests\ServiceRequestActorKind as Actor;
use App\Domain\ServiceRequests\ServiceRequestStatus as Status;
use App\Labs\F38\F38SyntheticRequestWriter;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * F38: no real Portal IAM or HTTP; writes ONLY to f38 synthetic fixtures
 * in a Docker PostgreSQL 18 container bound to random localhost port.
 */
final class F38SyntheticRequestWriteIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('F22_ENABLE_LAB') !== 'NON_OPERATIONAL_F22'
            || getenv('F38_ENABLE_LAB') !== 'SYNTHETIC_REQUEST_WRITE_ONLY') {
            self::markTestSkipped('F38 only runs on disposable PG18, not a live database.');
        }
    }

    protected function tearDown(): void
    {
        if (getenv('F38_ENABLE_LAB') === 'SYNTHETIC_REQUEST_WRITE_ONLY') {
            $admin=$this->db('admin');
            $admin->exec('DELETE FROM f38.command_receipt');
            $admin->exec("UPDATE f38.service_request
                            SET current_status='draft',revision=0,updated_at=1800000000");
        }
        parent::tearDown();
    }

    private function db(string $role): PDO
    {
        $ids=[
            'a'=>['f38_writer_a',getenv('F38_PG_PASSWORD_A')],
            'b'=>['f38_writer_b',getenv('F38_PG_PASSWORD_B')],
            'admin'=>['postgres',getenv('F22_PG_PASSWORD_ADMIN')],
        ];
        $port=getenv('F22_PG_PORT');
        if (!isset($ids[$role])
            || getenv('F22_PG_HOST') !== '127.0.0.1'
            || !is_string($port)
            || preg_match('/\A[0-9]{4,5}\z/D',$port)!==1
            || (int)$port>65535
            || !is_string($ids[$role][1])
            || preg_match('/\A[a-f0-9]{64}\z/D',$ids[$role][1])!==1) {
            throw new RuntimeException('F38 refuses non-ephemeral PostgreSQL credentials.');
        }
        return new PDO(
            'pgsql:host=127.0.0.1;port='.$port.';dbname=postgres;connect_timeout=3;sslmode=disable',
            $ids[$role][0],$ids[$role][1],
            [
                PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES=>false,
            ],
        );
    }

    private function writer(string $role='a'): F38SyntheticRequestWriter
    {
        return new F38SyntheticRequestWriter($this->db($role));
    }

    private function command(
        int $seq=1,
        string $tenant='a',
        Actor $actor=Actor::Customer,
        Action $action=Action::Submit,
        int $revision=0,
        ?string $request=null,
    ): DemoRequestCommand {
        return new DemoRequestCommand(
            'DEMO-CMD-'.strtoupper($tenant).'-'.str_pad((string)$seq,3,'0',STR_PAD_LEFT),
            $request ?? 'DEMO-REQUEST-'.strtoupper($tenant),
            new OrganizationId('org-'.$tenant),
            $actor,$action,$revision,1800000000+$seq,
        );
    }

    private function record(string $tenant='a'): array
    {
        $stmt=$this->db('admin')->prepare(
            'SELECT current_status AS status,revision FROM f38.service_request
              WHERE organization_id=:org AND request_id=:id'
        );
        $stmt->execute(['org'=>'org-'.$tenant,'id'=>'DEMO-REQUEST-'.strtoupper($tenant)]);
        $row=$stmt->fetch(PDO::FETCH_ASSOC);
        return ['status'=>$row['status'],'revision'=>(int)$row['revision']];
    }

    private function receipts(string $tenant='a'): int
    {
        $stmt=$this->db('admin')->prepare(
            'SELECT count(*) FROM f38.command_receipt WHERE organization_id=:org'
        );
        $stmt->execute(['org'=>'org-'.$tenant]);
        return (int)$stmt->fetchColumn();
    }

    public function test_real_pg18_transaction_updates_only_its_tenant_and_never_authorizes_http(): void
    {
        $a=$this->writer('a')->apply($this->command());
        self::assertSame('applied_synthetic_sql',$a->reason);
        self::assertSame(Status::Submitted,$a->candidate?->status);
        self::assertSame(1,$a->candidate?->revision);
        self::assertTrue($a->wasWrittenToDisposableFixture());
        self::assertFalse($a->mayWriteProduction());
        self::assertFalse($a->mayAuthorizeHttp());
        self::assertFalse($a->mayCreateContractOrPayment());
        self::assertSame(['status'=>'submitted','revision'=>1],$this->record('a'));
        self::assertSame(['status'=>'draft','revision'=>0],$this->record('b'));
        self::assertSame(1,$this->receipts('a'));

        $b=$this->writer('b')->apply($this->command(1,'b'));
        self::assertSame('applied_synthetic_sql',$b->reason);
        self::assertSame(1,$this->receipts('b'));
        self::assertSame(['status'=>'submitted','revision'=>1],$this->record('b'));
    }

    public function test_exact_replay_returns_original_committed_result_without_duplicate_receipt(): void
    {
        $writer=$this->writer('a');
        $one=$this->command();
        self::assertSame('applied_synthetic_sql',$writer->apply($one)->reason);
        self::assertSame('applied_synthetic_sql',$writer->apply(
            $this->command(2,'a',Actor::ImagineTeam,Action::StartReview,1),
        )->reason);
        $replayed=$writer->apply($one);
        self::assertSame('replayed_synthetic_sql',$replayed->reason);
        self::assertSame(Status::Submitted,$replayed->candidate?->status);
        self::assertSame(1,$replayed->candidate?->revision);
        self::assertSame(2,$this->receipts());
        self::assertSame(['status'=>'in_review','revision'=>2],$this->record());
        self::assertFalse($replayed->mayWriteProduction());
    }

    public function test_reused_command_id_with_different_payload_cannot_change_request(): void
    {
        $writer=$this->writer('a');
        self::assertTrue($writer->apply($this->command())->wasWrittenToDisposableFixture());
        $conflict=$writer->apply(
            $this->command(1,'a',Actor::ImagineTeam,Action::StartReview,1),
        );
        self::assertSame('idempotency_conflict',$conflict->reason);
        self::assertNull($conflict->candidate);
        self::assertSame(['status'=>'submitted','revision'=>1],$this->record());
        self::assertSame(1,$this->receipts());
    }

    public function test_stale_revision_and_invalid_business_role_do_not_insert_receipt(): void
    {
        $writer=$this->writer('a');
        self::assertSame('stale_revision',$writer->apply(
            $this->command(1,revision:9),
        )->reason);
        self::assertSame('transition_not_permitted',$writer->apply(
            $this->command(1,actor:Actor::ImagineTeam),
        )->reason);
        self::assertSame(0,$this->receipts());
        self::assertSame(['status'=>'draft','revision'=>0],$this->record());
        self::assertSame('applied_synthetic_sql',$writer->apply($this->command())->reason);
        self::assertSame(1,$this->receipts());
    }

    public function test_wrong_tenant_sql_identity_and_missing_resource_are_denied(): void
    {
        $a=$this->writer('a');
        self::assertSame('untrusted_db_role',$a->apply($this->command(1,'b'))->reason);
        self::assertSame('missing_synthetic_request',$a->apply(
            $this->command(1,request:'DEMO-REQUEST-UNKNOWN'),
        )->reason);
        $admin=new F38SyntheticRequestWriter($this->db('admin'));
        self::assertSame('untrusted_db_role',$admin->apply($this->command())->reason);
        self::assertSame(0,$this->receipts());
        self::assertSame(['status'=>'draft','revision'=>0],$this->record('a'));
        self::assertSame(['status'=>'draft','revision'=>0],$this->record('b'));
    }

    public function test_low_privileged_role_cannot_edit_other_tenant_or_delete_history(): void
    {
        $pdo=$this->db('a');
        $foreign=$pdo->query(
            "SELECT count(*) FROM f38.service_request WHERE organization_id='org-b'"
        )->fetchColumn();
        self::assertSame(0,(int)$foreign);
        $count=$pdo->exec(
            "UPDATE f38.service_request SET revision=1
              WHERE organization_id='org-b'"
        );
        self::assertSame(0,$count);
        foreach([
            'DELETE FROM f38.service_request',
            'DELETE FROM f38.command_receipt',
            'ALTER TABLE f38.service_request DISABLE ROW LEVEL SECURITY',
        ] as $sql){
            try{
                $pdo->exec($sql);
                self::fail('F38 reader/writer role can delete or disable its policy.');
            }catch(PDOException){
                self::addToAssertionCount(1);
            }
        }
        self::assertSame(['status'=>'draft','revision'=>0],$this->record('b'));
    }

    public function test_policy_force_rls_removed_causes_closed_denial(): void
    {
        $admin=$this->db('admin');
        $admin->exec('ALTER TABLE f38.command_receipt NO FORCE ROW LEVEL SECURITY');
        try{
            $denied=$this->writer('a')->apply($this->command());
            self::assertSame('unsafe_synthetic_schema',$denied->reason);
            self::assertSame(0,$this->receipts());
        }finally{
            $admin->exec('ALTER TABLE f38.command_receipt FORCE ROW LEVEL SECURITY');
        }
        self::assertSame('applied_synthetic_sql',$this->writer('a')->apply($this->command())->reason);
    }

    public function test_role_with_bypassrls_cannot_claim_transactional_request_authority(): void
    {
        $admin=$this->db('admin');
        $admin->exec('ALTER ROLE f38_writer_a BYPASSRLS');
        try{
            $result=$this->writer('a')->apply($this->command());
            self::assertSame('untrusted_db_role',$result->reason);
            self::assertSame(0,$this->receipts());
        }finally{
            $admin->exec('ALTER ROLE f38_writer_a NOBYPASSRLS');
        }
        self::assertSame('applied_synthetic_sql',$this->writer('a')->apply($this->command())->reason);
    }

    public function test_row_lock_timeout_rolls_back_and_same_command_can_retry(): void
    {
        $admin=$this->db('admin');
        $admin->beginTransaction();
        $admin->query(
            "SELECT revision FROM f38.service_request
              WHERE organization_id='org-a' AND request_id='DEMO-REQUEST-A'
              FOR UPDATE"
        )->closeCursor();
        try{
            $blocked=$this->writer('a')->apply($this->command());
            self::assertSame('source_unavailable',$blocked->reason);
            self::assertSame(0,$this->receipts());
        }finally{
            $admin->rollBack();
        }
        self::assertSame(['status'=>'draft','revision'=>0],$this->record());
        self::assertSame('applied_synthetic_sql',$this->writer('a')->apply($this->command())->reason);
    }

    public function test_receipt_insert_failure_rolls_back_service_status_atomically(): void
    {
        $admin=$this->db('admin');
        $admin->exec('REVOKE INSERT ON f38.command_receipt FROM f38_writer_a');
        try{
            $result=$this->writer('a')->apply($this->command());
            self::assertSame('source_unavailable',$result->reason);
            self::assertSame(['status'=>'draft','revision'=>0],$this->record());
            self::assertSame(0,$this->receipts());
        }finally{
            $admin->exec('GRANT INSERT ON f38.command_receipt TO f38_writer_a');
        }
        self::assertSame('applied_synthetic_sql',$this->writer('a')->apply($this->command())->reason);
        self::assertSame(['status'=>'submitted','revision'=>1],$this->record());
    }
}
