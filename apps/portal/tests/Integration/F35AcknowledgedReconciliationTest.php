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
use App\Labs\F35\F35WitnessAcknowledgementWriter;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * F35: two totally disposable PG18 containers, fake tenants, no HTTP or
 * permanently running worker. ACK is an observation CLAIM, not an independent
 * cryptographic attestation of the primary database.
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
            || getenv('F35_ENABLE_LAB') !== 'ACKNOWLEDGEMENTS_CI_ONLY') {
            self::markTestSkipped('F35 only runs against two independent disposable CI containers.');
        }
    }

    protected function tearDown(): void
    {
        if (getenv('F35_ENABLE_LAB') === 'ACKNOWLEDGEMENTS_CI_ONLY') {
            $this->restoreSyntheticState();
        }
        parent::tearDown();
    }

    private function db(string $cluster, string $role): PDO
    {
        $primary = [
            'admin' => ['postgres',getenv('F22_PG_PASSWORD_ADMIN')],
            'a' => ['f21_org_a',getenv('F22_PG_PASSWORD_A')],
            'b' => ['f21_org_b',getenv('F22_PG_PASSWORD_B')],
        ];
        $witness = [
            'admin' => ['postgres',getenv('F31_PG_PASSWORD_ADMIN')],
            'publisher' => ['f33_revocation_publisher',getenv('F33_PG_PASSWORD_PUBLISHER')],
            'ledger' => ['f34_reconciliation_reader',getenv('F34_PG_PASSWORD_READER')],
            'ack' => ['f35_acknowledgement_writer',getenv('F35_PG_PASSWORD_ACK')],
            'reader' => ['f31_witness_reader',getenv('F31_PG_PASSWORD_READER')],
        ];
        $port = $cluster === 'primary' ? getenv('F22_PG_PORT') : getenv('F31_PG_PORT');
        $identity = ($cluster === 'primary' ? $primary : $witness)[$role] ?? null;

        if (!in_array($cluster,['primary','witness'],true)
            || $identity === null
            || getenv('F22_PG_HOST') !== '127.0.0.1'
            || getenv('F31_PG_HOST') !== '127.0.0.1'
            || getenv('F22_PG_PORT') === getenv('F31_PG_PORT')
            || !is_string($port) || preg_match('/\A[0-9]{4,5}\z/D',$port) !== 1
            || (int) $port > 65535
            || !is_string($identity[1])
            || preg_match('/\A[a-f0-9]{64}\z/D',$identity[1]) !== 1) {
            throw new RuntimeException('F35 rejects non-ephemeral SQL endpoints and passwords.');
        }
        return new PDO(
            'pgsql:host=127.0.0.1;port='.$port.';dbname=postgres;connect_timeout=3;sslmode=disable',
            $identity[0],$identity[1],
            [
                PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES=>false,
            ],
        );
    }

    private function event(
        string $id,
        string $tenant='a',
        string $kind='session',
    ): F33SyntheticRevocationEvent {
        $suffix=strtoupper($tenant);
        return new F33SyntheticRevocationEvent(
            new OrganizationId('org-'.$tenant),$id,$kind,
            ($kind==='session'?'DEMO-SESSION-':'DEMO-USER-').$suffix,
            $kind==='session'?2:1,
            $kind==='membership'?2:1,
            1800000031,
        );
    }

    private function publisher(): F33WitnessFirstRevocationPublisher
    {
        return new F33WitnessFirstRevocationPublisher(
            $this->db('witness','publisher'),$this->db('primary','admin'),
        );
    }

    private function writer(): F35WitnessAcknowledgementWriter
    {
        return new F35WitnessAcknowledgementWriter($this->db('witness','ack'));
    }

    private function runner(
        ?PDO $primaryA=null,
        ?PDO $primaryB=null,
        ?PDO $ack=null,
        ?PDO $ledger=null,
    ): F35AcknowledgedReconciliationRunner {
        return new F35AcknowledgedReconciliationRunner(
            new F34SyntheticLedgerReader($ledger ?? $this->db('witness','ledger')),
            $this->publisher(),
            new F35PrimaryStateVerifier(
                $primaryA ?? $this->db('primary','a'),
                $primaryB ?? $this->db('primary','b'),
            ),
            new F35WitnessAcknowledgementWriter($ack ?? $this->db('witness','ack')),
        );
    }

    private function unackedCount(): int
    {
        return (int) $this->db('witness','admin')->query(
            'SELECT count(*) FROM f33.publication_ledger e
               LEFT JOIN f35.applied_receipt a ON a.event_id=e.event_id
              WHERE a.event_id IS NULL'
        )->fetchColumn();
    }

    private function ackCount(): int
    {
        return (int) $this->db('witness','admin')->query(
            'SELECT count(*) FROM f35.applied_receipt'
        )->fetchColumn();
    }

    private function primary(string $tenant='a'): array
    {
        $stmt=$this->db('primary','admin')->prepare(
            'SELECT mock_state AS state,session_revision AS rev FROM f24.session_fixture
              WHERE organization_id=:org'
        );
        $stmt->execute(['org'=>'org-'.$tenant]);
        $row=$stmt->fetch(PDO::FETCH_ASSOC);
        return ['state'=>$row['state'],'rev'=>(int) $row['rev']];
    }

    private function oldPreviewReason(string $tenant): string
    {
        $key=random_bytes(32);
        $suffix=strtoupper($tenant);
        $token=F24LabSessionProof::signFixture([
            'aud'=>'portal-f24-synthetic-read',
            'exp'=>1800000900,'iat'=>1800000000,
            'iss'=>'imagine-portal-f24-lab-only',
            'mfa'=>1800000000,'mrev'=>1,
            'nonce'=>str_repeat('4',32),
            'org'=>'org-'.$tenant,'sid'=>'DEMO-SESSION-'.$suffix,
            'srev'=>1,'sub'=>'DEMO-USER-'.$suffix,'ver'=>1,
        ],$key);
        $page=(new F31RollbackAwareLabReader(
            new F24SyntheticAuthorizedReader($this->db('primary',$tenant),$key),
            new F31ExternalWitnessReader($this->db('witness','reader')),
            $key,
        ))->read(new OrganizationId('org-'.$tenant),$token,self::NOW);
        self::assertFalse($page->authorizesServiceAccess());
        self::assertFalse($page->mayExposeCustomerData());
        return $page->reason;
    }

    private function restoreSyntheticState(): void
    {
        $a=$this->db('primary','admin');
        $a->exec("UPDATE f24.session_fixture SET mock_state='active',session_revision=1");
        $a->exec("UPDATE f24.membership_fixture
                    SET mock_state='active',membership_revision=1,
                        can_view_services=true");
        $w=$this->db('witness','admin');
        $w->exec('DELETE FROM f35.applied_receipt');
        $w->exec('DELETE FROM f33.publication_ledger');
        $w->exec('ALTER TABLE f31.revocation_floor DISABLE TRIGGER f31_floor_monotonic');
        try {
            $w->exec("UPDATE f31.revocation_floor SET session_floor=1,
                        membership_floor=1,sequence_number=1,changed_at=1800000000");
        } finally {
            $w->exec('ALTER TABLE f31.revocation_floor ENABLE TRIGGER f31_floor_monotonic');
        }
    }

    public function test_confirmed_empty_pending_batch_is_not_a_failure(): void
    {
        $result=$this->runner()->runOnce();
        self::assertTrue($result->completed);
        self::assertSame('complete',$result->status);
        self::assertSame(0,$result->discovered);
        self::assertSame(0,$result->applied);
        self::assertSame(0,$this->ackCount());
    }

    public function test_witness_published_event_is_applied_confirmed_and_not_replayed_after_ack(): void
    {
        $ev=$this->event('F33-F35-ACKED-A');
        self::assertSame('published',$this->publisher()->publishWitness($ev));
        self::assertSame(1,$this->unackedCount());
        self::assertSame('stale_external_witness',$this->oldPreviewReason('a'));

        $result=$this->runner()->runOnce();
        self::assertTrue($result->completed);
        self::assertSame(1,$result->discovered);
        self::assertSame(1,$result->applied);
        self::assertSame(['state'=>'revoked','rev'=>2],$this->primary());
        self::assertSame(1,$this->ackCount());
        self::assertSame(0,$this->unackedCount());

        $again=$this->runner()->runOnce();
        self::assertTrue($again->completed);
        self::assertSame(0,$again->discovered);
        self::assertSame(0,$again->applied);
        self::assertSame('stale_external_witness',$this->oldPreviewReason('a'));
    }

    public function test_ack_failure_keeps_primary_revoked_and_event_pending_for_retry(): void
    {
        $ev=$this->event('F33-F35-ACK-TIMEOUT');
        self::assertSame('published',$this->publisher()->publishWitness($ev));
        $admin=$this->db('witness','admin');
        $admin->exec('REVOKE EXECUTE ON FUNCTION f35.confirm_observed_primary(
            text,text,text,text,integer,bigint) FROM f35_acknowledgement_writer');
        try {
            $failed=$this->runner()->runOnce();
            self::assertFalse($failed->completed);
            self::assertSame('ack_pending',$failed->status);
            self::assertSame(0,$failed->applied);
            self::assertSame(1,$this->unackedCount());
            self::assertSame(['state'=>'revoked','rev'=>2],$this->primary());
            self::assertSame('stale_external_witness',$this->oldPreviewReason('a'));
        } finally {
            $admin->exec('GRANT EXECUTE ON FUNCTION f35.confirm_observed_primary(
                text,text,text,text,integer,bigint) TO f35_acknowledgement_writer');
        }
        $again=$this->runner()->runOnce();
        self::assertTrue($again->completed);
        self::assertSame(1,$again->applied);
        self::assertSame(0,$this->unackedCount());
    }

    public function test_wrong_primary_sql_role_never_produces_acknowledgement(): void
    {
        self::assertSame('published',$this->publisher()->publishWitness(
            $this->event('F33-F35-B-ROLE',tenant:'b'),
        ));
        $failed=$this->runner(primaryB:$this->db('primary','a'))->runOnce();
        self::assertFalse($failed->completed);
        self::assertSame('primary_unconfirmed',$failed->status);
        self::assertSame(0,$failed->applied);
        self::assertSame(['state'=>'revoked','rev'=>2],$this->primary('b'));
        self::assertSame(0,$this->ackCount());
        self::assertSame('stale_external_witness',$this->oldPreviewReason('b'));

        $success=$this->runner()->runOnce();
        self::assertTrue($success->completed);
        self::assertSame(1,$this->ackCount());
    }

    public function test_receipt_policy_disabled_causes_failure_not_a_false_empty_batch(): void
    {
        self::assertSame('published',$this->publisher()->publishWitness(
            $this->event('F33-F35-RLS-FAIL'),
        ));
        $admin=$this->db('witness','admin');
        $admin->exec('ALTER TABLE f35.applied_receipt NO FORCE ROW LEVEL SECURITY');
        try {
            $failed=$this->runner()->runOnce();
            self::assertFalse($failed->completed);
            self::assertSame('ledger_unavailable',$failed->status);
            self::assertSame(['state'=>'active','rev'=>1],$this->primary());
            self::assertSame(0,$this->ackCount());
        } finally {
            $admin->exec('ALTER TABLE f35.applied_receipt FORCE ROW LEVEL SECURITY');
        }
        self::assertTrue($this->runner()->runOnce()->completed);
        self::assertSame(1,$this->ackCount());
    }

    public function test_receipt_is_idempotent_and_different_revision_replay_is_rejected(): void
    {
        $ev=$this->event('F33-F35-RECEIPT-ID');
        self::assertSame('published',$this->publisher()->publishWitness($ev));
        self::assertTrue($this->runner()->runOnce()->completed);
        $writer=$this->writer();
        self::assertTrue($writer->confirm($ev,2));
        self::assertFalse($writer->confirm($ev,3));
        self::assertSame(1,$this->ackCount());
    }

    public function test_ack_role_cannot_read_or_write_ledger_floor_or_receipts_directly(): void
    {
        $pdo=$this->db('witness','ack');
        foreach ([
            "UPDATE f31.revocation_floor SET session_floor=1 WHERE organization_id='org-a'",
            "DELETE FROM f33.publication_ledger",
            "INSERT INTO f35.applied_receipt(event_id) VALUES ('F33-FAKE')",
            "SELECT * FROM f35.applied_receipt",
            "SELECT * FROM f33.publication_ledger",
        ] as $sql) {
            try {
                $pdo->query($sql);
                self::fail('Restricted ACK role reached unapproved SQL object.');
            } catch (PDOException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_two_tenants_and_different_event_types_receive_separate_confirmations(): void
    {
        self::assertSame('published',$this->publisher()->publishWitness(
            $this->event('F33-F35-A-SESSION'),
        ));
        self::assertSame('published',$this->publisher()->publishWitness(
            $this->event('F33-F35-B-MEMBERSHIP',tenant:'b',kind:'membership'),
        ));
        $done=$this->runner()->runOnce();
        self::assertTrue($done->completed);
        self::assertSame(2,$done->applied);
        self::assertSame(2,$this->ackCount());
        self::assertSame(0,$this->unackedCount());
        self::assertSame(['state'=>'revoked','rev'=>2],$this->primary('a'));
        self::assertSame(['state'=>'active','rev'=>1],$this->primary('b'));
        $b=$this->db('primary','admin')->query(
            "SELECT membership_revision,mock_state,can_view_services
               FROM f24.membership_fixture WHERE organization_id='org-b'"
        )->fetch(PDO::FETCH_ASSOC);
        self::assertSame(2,(int)$b['membership_revision']);
        self::assertSame('revoked',$b['mock_state']);
        self::assertContains($b['can_view_services'],[false,'f','false',0,'0']);
    }

    public function test_more_than_twenty_unacknowledged_events_fails_without_partial_ack(): void
    {
        $admin=$this->db('witness','admin');
        $stmt=$admin->prepare(
            'INSERT INTO f33.publication_ledger
              (event_id,organization_id,kind,subject_ref,session_floor,
               membership_floor,changed_at,sequence_number)
             VALUES (:id,:org,:kind,:subject,:sf,:mf,:at,:seq)'
        );
        for($seq=2;$seq<=22;$seq++){
            $stmt->execute([
                'id'=>'F33-F35-OVERLIMIT-'.$seq,
                'org'=>'org-a','kind'=>'session',
                'subject'=>'DEMO-SESSION-A',
                'sf'=>$seq,'mf'=>1,'at'=>1800000031,'seq'=>$seq,
            ]);
        }
        $outcome=$this->runner()->runOnce();
        self::assertFalse($outcome->completed);
        self::assertSame('ledger_unavailable',$outcome->status);
        self::assertSame(0,$outcome->applied);
        self::assertSame(['state'=>'active','rev'=>1],$this->primary());
    }
}
