<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Organizations\OrganizationId;
use App\Domain\ServiceRequests\DemoRequestCommand;
use App\Domain\ServiceRequests\ServiceRequestAction as Action;
use App\Domain\ServiceRequests\ServiceRequestActorKind as Actor;
use App\Labs\F38\F38SyntheticRequestWriter;
use App\Labs\F40\F40InMemoryFakeSink;
use App\Labs\F40\F40SyntheticOutboxRelay;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * All F40 relay effects are in-memory/PG18 disposable fixtures. Tests never
 * call real webhook, email, queue, CRM, Sites, Performance or payment APIs.
 */
final class F40SyntheticOutboxRelayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('F22_ENABLE_LAB')!=='NON_OPERATIONAL_F22'
            || getenv('F38_ENABLE_LAB')!=='SYNTHETIC_REQUEST_WRITE_ONLY'
            || getenv('F39_ENABLE_LAB')!=='TRANSACTIONAL_OUTBOX_CI_ONLY'
            || getenv('F40_ENABLE_LAB')!=='IN_MEMORY_FAKE_RELAY_ONLY') {
            self::markTestSkipped('F40 only uses two disposable PG18 GitHub CI containers.');
        }
    }

    protected function tearDown(): void
    {
        if (getenv('F40_ENABLE_LAB')==='IN_MEMORY_FAKE_RELAY_ONLY') {
            $db=$this->db('admin');
            $db->exec('DELETE FROM f40.fake_delivery_receipt');
            $db->exec('DELETE FROM f39.synthetic_request_outbox');
            $db->exec('DELETE FROM f38.command_receipt');
            $db->exec("UPDATE f38.service_request
                          SET current_status='draft',revision=0,updated_at=1800000000");
        }
        parent::tearDown();
    }

    private function db(string $role): PDO
    {
        $logins=[
            'admin'=>['postgres',getenv('F22_PG_PASSWORD_ADMIN')],
            'a'=>['f38_writer_a',getenv('F38_PG_PASSWORD_A')],
            'b'=>['f38_writer_b',getenv('F38_PG_PASSWORD_B')],
            'relay-a'=>['f40_relay_a',getenv('F40_PG_PASSWORD_A')],
            'relay-b'=>['f40_relay_b',getenv('F40_PG_PASSWORD_B')],
        ];
        $login=$logins[$role] ?? null;
        $port=getenv('F22_PG_PORT');
        if ($login===null || getenv('F22_PG_HOST')!=='127.0.0.1'
            || !is_string($port)
            || preg_match('/\A[0-9]{4,5}\z/D',$port)!==1
            || (int)$port>65535
            || !is_string($login[1])
            || preg_match('/\A[a-f0-9]{64}\z/D',$login[1])!==1) {
            throw new RuntimeException('F40 requires disposable loopback PostgreSQL18 credentials.');
        }
        return new PDO(
            'pgsql:host=127.0.0.1;port='.$port.';dbname=postgres;connect_timeout=3;sslmode=disable',
            $login[0],$login[1],
            [
                PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES=>false,
            ],
        );
    }

    private function relay(?F40InMemoryFakeSink $sink=null,?PDO $asA=null): F40SyntheticOutboxRelay
    {
        return new F40SyntheticOutboxRelay(
            $asA ?? $this->db('relay-a'),
            $this->db('relay-b'),
            $sink ?? new F40InMemoryFakeSink(),
        );
    }

    private function command(
        int $seq=1,
        string $tenant='a',
        Actor $actor=Actor::Customer,
        Action $action=Action::Submit,
        int $rev=0,
    ): DemoRequestCommand {
        $suffix=strtoupper($tenant);
        return new DemoRequestCommand(
            'DEMO-CMD-'.$suffix.'-'.str_pad((string)$seq,3,'0',STR_PAD_LEFT),
            'DEMO-REQUEST-'.$suffix,
            new OrganizationId('org-'.$tenant),
            $actor,$action,$rev,1800000000+$seq,
        );
    }

    private function publish(string $tenant='a',int $seq=1,Actor $actor=Actor::Customer,
                             Action $action=Action::Submit,int $rev=0): void
    {
        $writer=new F38SyntheticRequestWriter($this->db($tenant));
        $result=$writer->apply($this->command($seq,$tenant,$actor,$action,$rev),true);
        self::assertSame('applied_synthetic_sql',$result->reason);
        self::assertFalse($result->mayAuthorizeHttp());
    }

    private function ackCount(string $org='org-a'): int
    {
        $stmt=$this->db('admin')->prepare(
            'SELECT count(*) FROM f40.fake_delivery_receipt WHERE organization_id=:org'
        );
        $stmt->execute(['org'=>$org]);
        return (int)$stmt->fetchColumn();
    }

    public function test_empty_queue_is_confirmed_not_a_delivery(): void
    {
        $sink=new F40InMemoryFakeSink();
        $outcome=$this->relay($sink)->runOnce();
        self::assertTrue($outcome->completed);
        self::assertSame('complete',$outcome->status);
        self::assertSame(0,$outcome->discovered);
        self::assertSame(0,$outcome->recorded);
        self::assertSame(0,$sink->simulatedCount());
        self::assertFalse($outcome->deliveredExternally());
        self::assertFalse($outcome->authorizesCustomerAccess());
    }

    public function test_fake_relay_delivers_two_tenants_only_to_memory_and_records_two_sql_receipts(): void
    {
        $this->publish('a');
        $this->publish('b');
        $sink=new F40InMemoryFakeSink();
        $outcome=$this->relay($sink)->runOnce();
        self::assertTrue($outcome->completed);
        self::assertSame(2,$outcome->discovered);
        self::assertSame(2,$outcome->recorded);
        self::assertSame(2,$sink->simulatedCount());
        self::assertSame(1,$this->ackCount('org-a'));
        self::assertSame(1,$this->ackCount('org-b'));
        self::assertContains('F39-DEMO-CMD-A-001',$sink->references());
        self::assertContains('F39-DEMO-CMD-B-001',$sink->references());
        self::assertFalse($outcome->deliveredExternally());
    }

    public function test_second_run_skips_acked_events_without_duplicate_fake_delivery(): void
    {
        $this->publish();
        $sink=new F40InMemoryFakeSink();
        $relay=$this->relay($sink);
        self::assertSame(1,$relay->runOnce()->recorded);
        $again=$relay->runOnce();
        self::assertTrue($again->completed);
        self::assertSame(0,$again->discovered);
        self::assertSame(0,$again->recorded);
        self::assertSame(1,$sink->simulatedCount());
        self::assertSame(1,$this->ackCount());
    }

    public function test_fake_sink_failure_leaves_outbox_pending_and_retries(): void
    {
        $this->publish();
        $sink=new F40InMemoryFakeSink('F39-DEMO-CMD-A-001');
        $relay=$this->relay($sink);
        $blocked=$relay->runOnce();
        self::assertFalse($blocked->completed);
        self::assertSame('sink_unavailable',$blocked->status);
        self::assertSame(0,$blocked->recorded);
        self::assertSame(0,$sink->simulatedCount());
        self::assertSame(0,$this->ackCount());

        $retried=$relay->runOnce();
        self::assertTrue($retried->completed);
        self::assertSame(1,$retried->recorded);
        self::assertSame(1,$sink->simulatedCount());
        self::assertSame(1,$this->ackCount());
    }

    public function test_ack_function_unavailable_keeps_pending_for_idempotent_retry(): void
    {
        $this->publish();
        $sink=new F40InMemoryFakeSink();
        $relay=$this->relay($sink);
        $admin=$this->db('admin');
        $admin->exec('REVOKE EXECUTE ON FUNCTION f40.record_fake_delivery(
            text,text,text,text,bigint) FROM f40_relay_a');
        try {
            $blocked=$relay->runOnce();
            self::assertFalse($blocked->completed);
            self::assertSame('ack_pending',$blocked->status);
            self::assertSame(0,$blocked->recorded);
            self::assertSame(1,$sink->simulatedCount());
            self::assertSame(0,$this->ackCount());
        } finally {
            $admin->exec('GRANT EXECUTE ON FUNCTION f40.record_fake_delivery(
                text,text,text,text,bigint) TO f40_relay_a');
        }
        self::assertTrue($relay->runOnce()->completed);
        self::assertSame(1,$sink->simulatedCount());
        self::assertSame(1,$this->ackCount());
    }

    public function test_missing_read_grant_does_not_look_like_empty_queue(): void
    {
        $this->publish();
        $admin=$this->db('admin');
        $admin->exec('REVOKE SELECT ON f39.synthetic_request_outbox FROM f40_relay_a');
        try {
            $failed=$this->relay()->runOnce();
            self::assertFalse($failed->completed);
            self::assertSame('source_unavailable',$failed->status);
            self::assertSame(0,$this->ackCount());
        } finally {
            $admin->exec('GRANT SELECT ON f39.synthetic_request_outbox TO f40_relay_a');
        }
        self::assertTrue($this->relay()->runOnce()->completed);
    }

    public function test_rls_force_disabled_causes_closed_failure_before_fake_delivery(): void
    {
        $this->publish();
        $sink=new F40InMemoryFakeSink();
        $admin=$this->db('admin');
        $admin->exec('ALTER TABLE f40.fake_delivery_receipt NO FORCE ROW LEVEL SECURITY');
        try {
            $failed=$this->relay($sink)->runOnce();
            self::assertFalse($failed->completed);
            self::assertSame('source_unavailable',$failed->status);
            self::assertSame(0,$sink->simulatedCount());
            self::assertSame(0,$this->ackCount());
        } finally {
            $admin->exec('ALTER TABLE f40.fake_delivery_receipt FORCE ROW LEVEL SECURITY');
        }
        self::assertTrue($this->relay($sink)->runOnce()->completed);
    }

    public function test_relay_sql_identity_is_tenant_bound_and_cannot_write_tables_directly(): void
    {
        $this->publish('a');
        $this->publish('b');
        $pdo=$this->db('relay-a');
        self::assertSame(1,(int)$pdo->query(
            "SELECT count(*) FROM f39.synthetic_request_outbox WHERE organization_id='org-a'"
        )->fetchColumn());
        self::assertSame(0,(int)$pdo->query(
            "SELECT count(*) FROM f39.synthetic_request_outbox WHERE organization_id='org-b'"
        )->fetchColumn());
        foreach ([
            "UPDATE f39.synthetic_request_outbox SET occurred_at=1800000020 WHERE organization_id='org-a'",
            "DELETE FROM f39.synthetic_request_outbox WHERE organization_id='org-a'",
            "INSERT INTO f40.fake_delivery_receipt(organization_id) VALUES ('org-a')",
            'ALTER TABLE f40.fake_delivery_receipt DISABLE ROW LEVEL SECURITY',
        ] as $sql) {
            try {
                $pdo->exec($sql);
                self::fail('Fake relay role obtained unexpected table write/DDL.');
            } catch (PDOException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame(0,$this->ackCount());
    }

    public function test_wrong_role_and_forged_payload_hash_cannot_record_fake_receipt(): void
    {
        $this->publish();
        $wrongRole=$this->relay(asA:$this->db('admin'))->runOnce();
        self::assertFalse($wrongRole->completed);
        self::assertSame('source_unavailable',$wrongRole->status);

        $stmt=$this->db('relay-a')->prepare(
            'SELECT f40.record_fake_delivery(:org,:cmd,:ref,:sha,:at)'
        );
        try {
            $stmt->execute([
                'org'=>'org-a','cmd'=>'DEMO-CMD-A-001',
                'ref'=>'F39-DEMO-CMD-A-001',
                'sha'=>str_repeat('f',64),'at'=>1800000300,
            ]);
            self::fail('A forged payload digest was accepted.');
        } catch (PDOException) {
            self::addToAssertionCount(1);
        }
        self::assertSame(0,$this->ackCount());
    }

    public function test_two_pending_events_from_one_tenant_are_processed_in_order(): void
    {
        $this->publish('a');
        $this->publish('a',2,Actor::ImagineTeam,Action::StartReview,1);
        $sink=new F40InMemoryFakeSink();
        $result=$this->relay($sink)->runOnce();
        self::assertTrue($result->completed);
        self::assertSame(2,$result->recorded);
        self::assertSame(2,$this->ackCount());
        self::assertSame([
            'F39-DEMO-CMD-A-001','F39-DEMO-CMD-A-002',
        ],$sink->references());
        self::assertFalse($result->deliveredExternally());
    }
}
