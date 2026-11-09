<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Organizations\OrganizationId;
use App\Labs\F24\F24LabSessionProof;
use App\Labs\F24\F24SyntheticAuthorizedReader;
use App\Labs\F31\F31ExternalWitnessReader;
use App\Labs\F31\F31RollbackAwareLabReader;
use App\Labs\F33\F33SyntheticRevocationEvent;
use App\Labs\F33\F33WitnessFirstRevocationPublisher;
use App\Labs\F34\F34SyntheticLedgerReader;
use App\Labs\F35\F35AcknowledgedReconciliationRunner;
use App\Labs\F35\F35PrimaryStateVerifier;
use App\Labs\F35\F35WitnessAckStore;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Entirely fictional two-PG18 CI. Acknowledgements are checkpoints only,
 * never an external proof of delivery or customer authorization.
 */
final class F35AcknowledgedReconciliationTest extends TestCase
{
    private const NOW = 1800000030;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('F22_ENABLE_LAB') !== 'NON_OPERATIONAL_F22'
            || getenv('F31_ENABLE_LAB') !== 'INDEPENDENT_DISPOSABLE_WITNESS'
            || getenv('F33_ENABLE_LAB') !== 'WITNESS_FIRST_SYNTHETIC_ONLY'
            || getenv('F34_ENABLE_LAB') !== 'BOUNDED_REPLAY_ONLY'
            || getenv('F35_ENABLE_LAB') !== 'ACK_ONLY_DISPOSABLE_PG18') {
            self::markTestSkipped('F35 only runs in two disposable PG18 CI containers.');
        }
    }

    protected function tearDown(): void
    {
        if (getenv('F35_ENABLE_LAB') === 'ACK_ONLY_DISPOSABLE_PG18') {
            $this->resetFixtures();
        }
        parent::tearDown();
    }

    private function db(string $cluster, string $role): PDO
    {
        $primary = [
            'admin' => ['postgres', getenv('F22_PG_PASSWORD_ADMIN')],
            'a' => ['f21_org_a', getenv('F22_PG_PASSWORD_A')],
            'b' => ['f21_org_b', getenv('F22_PG_PASSWORD_B')],
        ];
        $witness = [
            'admin' => ['postgres', getenv('F31_PG_PASSWORD_ADMIN')],
            'reader' => ['f31_witness_reader', getenv('F31_PG_PASSWORD_READER')],
            'publisher' => ['f33_revocation_publisher', getenv('F33_PG_PASSWORD_PUBLISHER')],
            'ledger' => ['f34_reconciliation_reader', getenv('F34_PG_PASSWORD_READER')],
            'ack' => ['f35_ack_worker', getenv('F35_PG_PASSWORD_ACK')],
        ];
        $port = $cluster === 'primary' ? getenv('F22_PG_PORT') : getenv('F31_PG_PORT');
        $creds = ($cluster === 'primary' ? $primary : $witness)[$role] ?? null;
        if (!in_array($cluster, ['primary','witness'], true)
            || $creds === null
            || getenv('F22_PG_HOST') !== '127.0.0.1'
            || getenv('F31_PG_HOST') !== '127.0.0.1'
            || getenv('F22_PG_PORT') === getenv('F31_PG_PORT')
            || !is_string($port)
            || preg_match('/\A[0-9]{4,5}\z/D', $port) !== 1
            || (int) $port > 65535
            || !is_string($creds[1])
            || preg_match('/\A[a-f0-9]{64}\z/D', $creds[1]) !== 1) {
            throw new RuntimeException('F35 refuses non-disposable PostgreSQL connection.');
        }
        return new PDO(
            'pgsql:host=127.0.0.1;port='.$port.';dbname=postgres;connect_timeout=3;sslmode=disable',
            $creds[0], $creds[1],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        );
    }

    private function event(string $id, string $tenant = 'a', string $kind = 'session'): F33SyntheticRevocationEvent
    {
        return new F33SyntheticRevocationEvent(
            new OrganizationId('org-'.$tenant),
            $id,
            $kind,
            ($kind === 'session' ? 'DEMO-SESSION-' : 'DEMO-USER-').strtoupper($tenant),
            $kind === 'session' ? 2 : 1,
            $kind === 'membership' ? 2 : 1,
            1800000031,
        );
    }

    private function publisher(): F33WitnessFirstRevocationPublisher
    {
        return new F33WitnessFirstRevocationPublisher(
            $this->db('witness','publisher'),
            $this->db('primary','admin'),
        );
    }

    private function acks(?PDO $db = null): F35WitnessAckStore
    {
        return new F35WitnessAckStore($db ?? $this->db('witness','ack'));
    }

    private function verifier(?PDO $readerA = null): F35PrimaryStateVerifier
    {
        return new F35PrimaryStateVerifier(
            $readerA ?? $this->db('primary','a'),
            $this->db('primary','b'),
        );
    }

    private function runner(?PDO $ackConnection = null, ?PDO $readerA = null): F35AcknowledgedReconciliationRunner
    {
        return new F35AcknowledgedReconciliationRunner(
            new F34SyntheticLedgerReader($this->db('witness','ledger')),
            $this->publisher(),
            $this->verifier($readerA),
            $this->acks($ackConnection),
        );
    }

    private function ackCount(): int
    {
        return (int) $this->db('witness','admin')->query(
            'SELECT count(*) FROM f35.applied_event_ack'
        )->fetchColumn();
    }

    private function primaryState(string $tenant = 'a', string $kind = 'session'): array
    {
        $table = $kind === 'session' ? 'session_fixture' : 'membership_fixture';
        $column = $kind === 'session' ? 'session_revision' : 'membership_revision';
        $stmt = $this->db('primary','admin')->prepare(
            'SELECT mock_state, '.$column.' AS revision FROM f24.'.$table.'
              WHERE organization_id=:org'
        );
        $stmt->execute(['org'=>'org-'.$tenant]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return ['state'=>$row['mock_state'],'revision'=>(int) $row['revision']];
    }

    private function oldToken(string $key, string $tenant = 'a'): string
    {
        return F24LabSessionProof::signFixture([
            'aud'=>'portal-f24-synthetic-read',
            'exp'=>1800000900,
            'iat'=>1800000000,
            'iss'=>'imagine-portal-f24-lab-only',
            'mfa'=>1800000000,
            'mrev'=>1,
            'nonce'=>str_repeat('7',32),
            'org'=>'org-'.$tenant,
            'sid'=>'DEMO-SESSION-'.strtoupper($tenant),
            'srev'=>1,
            'sub'=>'DEMO-USER-'.strtoupper($tenant),
            'ver'=>1,
        ], $key);
    }

    private function readOldProof(string $tenant = 'a'): string
    {
        $key = random_bytes(32);
        $reader = new F31RollbackAwareLabReader(
            new F24SyntheticAuthorizedReader($this->db('primary',$tenant),$key),
            new F31ExternalWitnessReader($this->db('witness','reader')),
            $key,
        );
        return $reader->read(new OrganizationId('org-'.$tenant),$this->oldToken($key,$tenant),self::NOW)->reason;
    }

    private function resetFixtures(): void
    {
        $primary=$this->db('primary','admin');
        $primary->exec("UPDATE f24.session_fixture SET mock_state='active',session_revision=1");
        $primary->exec("UPDATE f24.membership_fixture
                           SET mock_state='active',membership_revision=1,can_view_services=true");

        $witness=$this->db('witness','admin');
        $witness->exec('DELETE FROM f35.applied_event_ack');
        $witness->exec('DELETE FROM f33.publication_ledger');
        $witness->exec('ALTER TABLE f31.revocation_floor DISABLE TRIGGER f31_floor_monotonic');
        try {
            $witness->exec("UPDATE f31.revocation_floor
                              SET session_floor=1,membership_floor=1,
                                  sequence_number=1,changed_at=1800000000");
        } finally {
            $witness->exec('ALTER TABLE f31.revocation_floor ENABLE TRIGGER f31_floor_monotonic');
        }
    }

    public function test_confirmed_empty_ledger_does_not_create_ack(): void
    {
        $out=$this->runner()->runOnce();
        self::assertTrue($out->completed);
        self::assertSame('complete',$out->status);
        self::assertSame(0,$out->discovered);
        self::assertSame(0,$out->verified);
        self::assertSame(0,$out->newAcknowledgements);
        self::assertSame(0,$this->ackCount());
    }

    public function test_witness_published_event_is_primary_applied_then_acknowledged(): void
    {
        $event=$this->event('F33-F35-PENDING-A');
        self::assertSame('published',$this->publisher()->publishWitness($event));
        self::assertSame(['state'=>'active','revision'=>1],$this->primaryState());
        self::assertFalse($this->acks()->isAcknowledged($event));

        $out=$this->runner()->runOnce();
        self::assertTrue($out->completed);
        self::assertSame(1,$out->verified);
        self::assertSame(1,$out->primaryRepairs);
        self::assertSame(1,$out->newAcknowledgements);
        self::assertSame(['state'=>'revoked','revision'=>2],$this->primaryState());
        self::assertTrue($this->acks()->isAcknowledged($event));
        self::assertSame(1,$this->ackCount());
        self::assertSame('stale_external_witness',$this->readOldProof());

        $repeat=$this->runner()->runOnce();
        self::assertTrue($repeat->completed);
        self::assertSame(1,$repeat->verified);
        self::assertSame(0,$repeat->primaryRepairs);
        self::assertSame(0,$repeat->newAcknowledgements);
        self::assertSame(1,$this->ackCount());
    }

    public function test_crash_after_primary_commit_before_ack_can_resume_without_double_write(): void
    {
        $event=$this->event('F33-F35-CRASH-AFTER-PRIMARY');
        self::assertSame('published',$this->publisher()->publishWitness($event));
        self::assertTrue($this->publisher()->finishPrimary($event));
        self::assertSame(['state'=>'revoked','revision'=>2],$this->primaryState());
        self::assertFalse($this->acks()->isAcknowledged($event));

        // Simulated process exit: F35 is invoked separately later.
        $out=$this->runner()->runOnce();
        self::assertTrue($out->completed);
        self::assertSame(0,$out->primaryRepairs);
        self::assertSame(1,$out->newAcknowledgements);
        self::assertTrue($this->acks()->isAcknowledged($event));
    }

    public function test_ack_permission_failure_after_primary_commit_never_reports_complete(): void
    {
        $event=$this->event('F33-F35-ACK-REVOKED');
        self::assertSame('published',$this->publisher()->publishWitness($event));
        $admin=$this->db('witness','admin');
        $admin->exec('REVOKE EXECUTE ON FUNCTION f35.ack_applied(
            text,text,text,text,integer,integer,bigint,bigint) FROM f35_ack_worker');
        try {
            $out=$this->runner()->runOnce();
            self::assertFalse($out->completed);
            self::assertSame('ack_pending',$out->status);
            self::assertSame(1,$out->primaryRepairs);
            self::assertSame(0,$this->ackCount());
            self::assertSame(['state'=>'revoked','revision'=>2],$this->primaryState());
        } finally {
            $admin->exec('GRANT EXECUTE ON FUNCTION f35.ack_applied(
                text,text,text,text,integer,integer,bigint,bigint) TO f35_ack_worker');
        }

        $retry=$this->runner()->runOnce();
        self::assertTrue($retry->completed);
        self::assertSame(0,$retry->primaryRepairs);
        self::assertSame(1,$retry->newAcknowledgements);
        self::assertSame(1,$this->ackCount());
    }

    public function test_prior_ack_does_not_skip_primary_state_after_rollback_simulation(): void
    {
        $event=$this->event('F33-F35-ROLLBACK-PRIMARY');
        self::assertSame('published',$this->publisher()->publishWitness($event));
        self::assertTrue($this->runner()->runOnce()->completed);
        self::assertTrue($this->acks()->isAcknowledged($event));

        // Simulate restore of only PRIMARY session row, not witness floor/ack.
        $this->db('primary','admin')->exec(
            "UPDATE f24.session_fixture SET mock_state='active',session_revision=1
              WHERE organization_id='org-a' AND session_id='DEMO-SESSION-A'"
        );
        self::assertSame(['state'=>'active','revision'=>1],$this->primaryState());
        self::assertSame('stale_external_witness',$this->readOldProof());

        $repair=$this->runner()->runOnce();
        self::assertTrue($repair->completed);
        self::assertSame(1,$repair->primaryRepairs);
        self::assertSame(0,$repair->newAcknowledgements);
        self::assertSame(['state'=>'revoked','revision'=>2],$this->primaryState());
        self::assertSame(1,$this->ackCount());
    }

    public function test_ack_for_missing_or_mismatched_ledger_event_is_rejected(): void
    {
        $fake=$this->event('F33-F35-NOT-PUBLISHED');
        self::assertNull($this->acks()->record($fake));
        self::assertSame(0,$this->ackCount());

        $actual=$this->event('F33-F35-EXACT-PAYLOAD');
        self::assertSame('published',$this->publisher()->publishWitness($actual));
        $wrong=new F33SyntheticRevocationEvent(
            new OrganizationId('org-a'),'F33-F35-EXACT-PAYLOAD',
            'session','DEMO-SESSION-A',3,1,1800000031,
        );
        self::assertNull($this->acks()->record($wrong));
        self::assertFalse($this->acks()->isAcknowledged($actual));
        self::assertSame(0,$this->ackCount());
    }

    public function test_ack_role_cannot_directly_modify_ack_ledger_or_revocation_floor(): void
    {
        $pdo=$this->db('witness','ack');
        foreach ([
            "DELETE FROM f35.applied_event_ack",
            "UPDATE f35.applied_event_ack SET session_floor=1",
            "INSERT INTO f35.applied_event_ack(event_id) VALUES ('F33-F35-MALICIOUS')",
            "DELETE FROM f33.publication_ledger",
            "UPDATE f31.revocation_floor SET session_floor=1 WHERE organization_id='org-a'",
        ] as $sql) {
            try {
                $pdo->exec($sql);
                self::fail('F35 restricted ack role gained unexpected SQL write.');
            } catch (PDOException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame(0,$this->ackCount());
    }

    public function test_admin_ack_identity_or_bad_primary_role_causes_failure(): void
    {
        $event=$this->event('F33-F35-BAD-IDENTITY');
        self::assertSame('published',$this->publisher()->publishWitness($event));
        $adminRunner=$this->runner(ackConnection:$this->db('witness','admin'));
        $badAck=$adminRunner->runOnce();
        self::assertFalse($badAck->completed);
        self::assertSame('ack_unavailable',$badAck->status);

        $badPrimary=$this->runner(readerA:$this->db('primary','b'))->runOnce();
        self::assertFalse($badPrimary->completed);
        self::assertSame('primary_verify_unavailable',$badPrimary->status);
        self::assertSame(0,$this->ackCount());
        self::assertSame(['state'=>'active','revision'=>1],$this->primaryState());

        self::assertTrue($this->runner()->runOnce()->completed);
    }

    public function test_two_tenants_and_membership_revocation_are_acknowledged_separately(): void
    {
        self::assertSame('published',$this->publisher()->publishWitness(
            $this->event('F33-F35-ORG-A')
        ));
        self::assertSame('published',$this->publisher()->publishWitness(
            $this->event('F33-F35-ORG-B-MEMBER','b','membership')
        ));
        $out=$this->runner()->runOnce();
        self::assertTrue($out->completed);
        self::assertSame(2,$out->discovered);
        self::assertSame(2,$out->verified);
        self::assertSame(2,$out->newAcknowledgements);
        self::assertSame(2,$out->primaryRepairs);
        self::assertSame(2,$this->ackCount());
        self::assertSame(['state'=>'revoked','revision'=>2],$this->primaryState('b','membership'));
        self::assertSame(['state'=>'active','revision'=>1],$this->primaryState('b'));
    }

    public function test_ack_select_revocation_is_unavailable_not_a_false_missing_checkpoint(): void
    {
        $event=$this->event('F33-F35-ACK-SELECT-REVOKED');
        self::assertSame('published',$this->publisher()->publishWitness($event));
        $admin=$this->db('witness','admin');
        $admin->exec('REVOKE SELECT ON f35.applied_event_ack FROM f35_ack_worker');
        try {
            self::assertNull($this->acks()->isAcknowledged($event));
            $out=$this->runner()->runOnce();
            self::assertFalse($out->completed);
            self::assertSame('ack_unavailable',$out->status);
            self::assertSame(['state'=>'active','revision'=>1],$this->primaryState());
        } finally {
            $admin->exec('GRANT SELECT ON f35.applied_event_ack TO f35_ack_worker');
        }
        self::assertTrue($this->runner()->runOnce()->completed);
    }
}
