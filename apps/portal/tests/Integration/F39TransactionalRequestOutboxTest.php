<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Organizations\OrganizationId;
use App\Domain\ServiceRequests\DemoRequestCommand;
use App\Domain\ServiceRequests\ServiceRequestAction as Action;
use App\Domain\ServiceRequests\ServiceRequestActorKind as Actor;
use App\Labs\F38\F38SyntheticRequestWriter;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** CI-only PG18 outbox, no actual notifications, webhook, queue or customer. */
final class F39TransactionalRequestOutboxTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('F22_ENABLE_LAB') !== 'NON_OPERATIONAL_F22'
            || getenv('F38_ENABLE_LAB') !== 'SYNTHETIC_REQUEST_WRITE_ONLY'
            || getenv('F39_ENABLE_LAB') !== 'TRANSACTIONAL_OUTBOX_CI_ONLY') {
            self::markTestSkipped('F39 only runs with disposable two-container PG18 CI.');
        }
    }

    protected function tearDown(): void
    {
        if (getenv('F39_ENABLE_LAB') === 'TRANSACTIONAL_OUTBOX_CI_ONLY') {
            $admin=$this->db('admin');
            $admin->exec('DELETE FROM f39.synthetic_request_outbox');
            $admin->exec('DELETE FROM f38.command_receipt');
            $admin->exec("UPDATE f38.service_request
                            SET current_status='draft',revision=0,
                                updated_at=1800000000");
        }
        parent::tearDown();
    }

    private function db(string $role): PDO
    {
        $credentials=[
            'a'=>['f38_writer_a',getenv('F38_PG_PASSWORD_A')],
            'b'=>['f38_writer_b',getenv('F38_PG_PASSWORD_B')],
            'admin'=>['postgres',getenv('F22_PG_PASSWORD_ADMIN')],
        ];
        $port=getenv('F22_PG_PORT');
        if(!isset($credentials[$role])
            || getenv('F22_PG_HOST')!=='127.0.0.1'
            || !is_string($port)
            || preg_match('/\A[0-9]{4,5}\z/D',$port)!==1
            || (int)$port>65535
            || !is_string($credentials[$role][1])
            || preg_match('/\A[a-f0-9]{64}\z/D',$credentials[$role][1])!==1){
            throw new RuntimeException('F39 refuses non-local disposable SQL role or secret.');
        }
        return new PDO(
            'pgsql:host=127.0.0.1;port='.$port.';dbname=postgres;connect_timeout=3;sslmode=disable',
            $credentials[$role][0],$credentials[$role][1],
            [
                PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES=>false,
            ],
        );
    }

    private function command(
        int $seq=1,
        string $tenant='a',
        Actor $actor=Actor::Customer,
        Action $action=Action::Submit,
        int $revision=0,
    ): DemoRequestCommand {
        $suffix=strtoupper($tenant);
        return new DemoRequestCommand(
            'DEMO-CMD-'.$suffix.'-'.str_pad((string)$seq,3,'0',STR_PAD_LEFT),
            'DEMO-REQUEST-'.$suffix,
            new OrganizationId('org-'.$tenant),
            $actor,$action,$revision,1800000000+$seq,
        );
    }

    private function writer(string $role='a'): F38SyntheticRequestWriter
    {
        return new F38SyntheticRequestWriter($this->db($role));
    }

    private function outboxCount(string $tenant='a'): int
    {
        $stmt=$this->db('admin')->prepare(
            'SELECT count(*) FROM f39.synthetic_request_outbox
              WHERE organization_id=:org'
        );
        $stmt->execute(['org'=>'org-'.$tenant]);
        return (int)$stmt->fetchColumn();
    }

    private function receiptCount(string $tenant='a'): int
    {
        $stmt=$this->db('admin')->prepare(
            'SELECT count(*) FROM f38.command_receipt
              WHERE organization_id=:org'
        );
        $stmt->execute(['org'=>'org-'.$tenant]);
        return (int)$stmt->fetchColumn();
    }

    private function requestState(string $tenant='a'): array
    {
        $stmt=$this->db('admin')->prepare(
            'SELECT current_status AS state,revision FROM f38.service_request
              WHERE organization_id=:org'
        );
        $stmt->execute(['org'=>'org-'.$tenant]);
        $row=$stmt->fetch(PDO::FETCH_ASSOC);
        return ['state'=>$row['state'],'revision'=>(int)$row['revision']];
    }

    public function test_one_transaction_commits_request_receipt_and_outbox_together(): void
    {
        $result=$this->writer()->apply($this->command(),writeSyntheticOutbox:true);
        self::assertSame('applied_synthetic_sql',$result->reason);
        self::assertTrue($result->wasWrittenToDisposableFixture());
        self::assertFalse($result->mayAuthorizeHttp());
        self::assertFalse($result->mayCreateContractOrPayment());
        self::assertSame(['state'=>'submitted','revision'=>1],$this->requestState());
        self::assertSame(1,$this->receiptCount());
        self::assertSame(1,$this->outboxCount());
        self::assertSame(0,$this->outboxCount('b'));

        $row=$this->db('admin')->query(
            "SELECT outbox_reference,event_type,payload::text AS payload
               FROM f39.synthetic_request_outbox
              WHERE organization_id='org-a'"
        )->fetch(PDO::FETCH_ASSOC);
        self::assertSame('F39-DEMO-CMD-A-001',$row['outbox_reference']);
        self::assertSame('service_request.status_changed.demo',$row['event_type']);
        $payload=json_decode($row['payload'],true,512,JSON_THROW_ON_ERROR);
        self::assertSame('f39.demo.v1',$payload['schema']);
        self::assertSame('draft',$payload['from_status']);
        self::assertSame('submitted',$payload['to_status']);
        self::assertSame(1,$payload['revision']);
        self::assertArrayNotHasKey('email',$payload);
        self::assertArrayNotHasKey('customer_name',$payload);
    }

    public function test_idempotent_replay_creates_no_duplicate_outbox_after_later_transition(): void
    {
        $writer=$this->writer();
        $one=$this->command();
        self::assertSame('applied_synthetic_sql',$writer->apply($one,true)->reason);
        self::assertSame('applied_synthetic_sql',$writer->apply(
            $this->command(2,'a',Actor::ImagineTeam,Action::StartReview,1),true,
        )->reason);
        $repeat=$writer->apply($one,true);
        self::assertSame('replayed_synthetic_sql',$repeat->reason);
        self::assertSame(1,$repeat->candidate?->revision);
        self::assertSame(2,$this->receiptCount());
        self::assertSame(2,$this->outboxCount());
        self::assertSame(['state'=>'in_review','revision'=>2],$this->requestState());
    }

    public function test_outbox_insert_denial_rolls_back_request_and_receipt(): void
    {
        $admin=$this->db('admin');
        $admin->exec('REVOKE INSERT ON f39.synthetic_request_outbox FROM f38_writer_a');
        try {
            $denied=$this->writer()->apply($this->command(),true);
            self::assertSame('source_unavailable',$denied->reason);
            self::assertSame(['state'=>'draft','revision'=>0],$this->requestState());
            self::assertSame(0,$this->receiptCount());
            self::assertSame(0,$this->outboxCount());
        } finally {
            $admin->exec('GRANT INSERT ON f39.synthetic_request_outbox TO f38_writer_a');
        }
        self::assertSame('applied_synthetic_sql',$this->writer()->apply($this->command(),true)->reason);
    }

    public function test_legacy_f38_receipt_without_outbox_is_not_represented_as_delivered(): void
    {
        $writer=$this->writer();
        $command=$this->command();
        self::assertSame('applied_synthetic_sql',$writer->apply($command)->reason);
        self::assertSame(1,$this->receiptCount());
        self::assertSame(0,$this->outboxCount());

        $denied=$writer->apply($command,true);
        self::assertSame('missing_synthetic_outbox',$denied->reason);
        self::assertFalse($denied->mayWriteProduction());
        self::assertSame(0,$this->outboxCount());
        self::assertSame(['state'=>'submitted','revision'=>1],$this->requestState());
    }

    public function test_outbox_force_rls_drift_is_detected_before_any_business_update(): void
    {
        $admin=$this->db('admin');
        $admin->exec('ALTER TABLE f39.synthetic_request_outbox NO FORCE ROW LEVEL SECURITY');
        try {
            $denied=$this->writer()->apply($this->command(),true);
            self::assertSame('unsafe_synthetic_schema',$denied->reason);
            self::assertSame(['state'=>'draft','revision'=>0],$this->requestState());
            self::assertSame(0,$this->receiptCount());
        } finally {
            $admin->exec('ALTER TABLE f39.synthetic_request_outbox FORCE ROW LEVEL SECURITY');
        }
        self::assertSame('applied_synthetic_sql',$this->writer()->apply($this->command(),true)->reason);
    }

    public function test_outbox_visibility_is_tenant_isolated_and_rows_are_not_editable(): void
    {
        self::assertSame('applied_synthetic_sql',$this->writer('a')->apply(
            $this->command(1,'a'),true,
        )->reason);
        self::assertSame('applied_synthetic_sql',$this->writer('b')->apply(
            $this->command(1,'b'),true,
        )->reason);
        $a=$this->db('a');
        self::assertSame(1,(int)$a->query(
            "SELECT count(*) FROM f39.synthetic_request_outbox WHERE organization_id='org-a'"
        )->fetchColumn());
        self::assertSame(0,(int)$a->query(
            "SELECT count(*) FROM f39.synthetic_request_outbox WHERE organization_id='org-b'"
        )->fetchColumn());
        foreach([
            "UPDATE f39.synthetic_request_outbox SET occurred_at=1800000030
              WHERE organization_id='org-a'",
            "DELETE FROM f39.synthetic_request_outbox WHERE organization_id='org-a'",
            "ALTER TABLE f39.synthetic_request_outbox DISABLE ROW LEVEL SECURITY",
        ] as $sql){
            try{
                $a->exec($sql);
                self::fail('Unprivileged tenant can mutate or weaken synthetic outbox.');
            }catch(PDOException){
                self::addToAssertionCount(1);
            }
        }
        self::assertSame(1,$this->outboxCount('a'));
        self::assertSame(1,$this->outboxCount('b'));
    }

    public function test_orphan_outbox_event_cannot_be_inserted_before_command_receipt(): void
    {
        $pdo=$this->db('a');
        try{
            $pdo->exec(
                "INSERT INTO f39.synthetic_request_outbox(
                    organization_id,command_id,outbox_reference,
                    event_type,payload,occurred_at)
                 VALUES (
                    'org-a','DEMO-CMD-A-UNSEEN','F39-DEMO-CMD-A-UNSEEN',
                    'service_request.status_changed.demo','{}'::jsonb,1800000001)"
            );
            self::fail('Orphan outbox event inserted despite FK.');
        }catch(PDOException){
            self::addToAssertionCount(1);
        }
        self::assertSame(0,$this->outboxCount());
        self::assertSame(['state'=>'draft','revision'=>0],$this->requestState());
    }

    public function test_conflicting_or_stale_commands_do_not_enqueue_events(): void
    {
        $writer=$this->writer();
        $first=$this->command(1);
        self::assertSame('applied_synthetic_sql',$writer->apply($first,true)->reason);
        self::assertSame('idempotency_conflict',$writer->apply(
            $this->command(1,'a',Actor::ImagineTeam,Action::StartReview,1),true,
        )->reason);
        self::assertSame('stale_revision',$writer->apply(
            $this->command(3,'a',Actor::ImagineTeam,Action::StartReview,0),true,
        )->reason);
        self::assertSame(1,$this->receiptCount());
        self::assertSame(1,$this->outboxCount());
    }
}
