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
use App\Labs\F34\F34ReconciliationRunner;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Only invoked inside CI with TWO transient PostgreSQL 18 fixture clusters.
 * No real worker or permanent scheduled job is created.
 */
final class F34ReconciliationReplayTest extends TestCase
{
    private const NOW = 1800000030;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('F22_ENABLE_LAB') !== 'NON_OPERATIONAL_F22'
            || getenv('F31_ENABLE_LAB') !== 'INDEPENDENT_DISPOSABLE_WITNESS'
            || getenv('F33_ENABLE_LAB') !== 'WITNESS_FIRST_SYNTHETIC_ONLY'
            || getenv('F34_ENABLE_LAB') !== 'BOUNDED_REPLAY_ONLY') {
            self::markTestSkipped('F34 runs only inside a two-container disposable CI runner.');
        }
    }

    protected function tearDown(): void
    {
        if (getenv('F34_ENABLE_LAB') === 'BOUNDED_REPLAY_ONLY') {
            $this->resetFixture();
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
            'replay' => ['f34_reconciliation_reader', getenv('F34_PG_PASSWORD_READER')],
        ];
        $port = $cluster === 'primary' ? getenv('F22_PG_PORT') : getenv('F31_PG_PORT');
        $info = ($cluster === 'primary' ? $primary : $witness)[$role] ?? null;
        if (!in_array($cluster, ['primary','witness'], true)
            || $info === null
            || getenv('F22_PG_HOST') !== '127.0.0.1'
            || getenv('F31_PG_HOST') !== '127.0.0.1'
            || getenv('F22_PG_PORT') === getenv('F31_PG_PORT')
            || !is_string($port)
            || preg_match('/\A[0-9]{4,5}\z/D', $port) !== 1
            || (int) $port > 65535
            || !is_string($info[1])
            || preg_match('/\A[a-f0-9]{64}\z/D', $info[1]) !== 1) {
            throw new RuntimeException('F34 refuses non-disposable SQL endpoints or credentials.');
        }
        return new PDO(
            'pgsql:host=127.0.0.1;port='.$port.';dbname=postgres;connect_timeout=3;sslmode=disable',
            $info[0], $info[1],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        );
    }

    private function org(string $tenant): OrganizationId
    {
        return new OrganizationId('org-'.$tenant);
    }

    private function event(
        string $id,
        string $tenant = 'a',
        string $kind = 'session',
    ): F33SyntheticRevocationEvent {
        $suffix = strtoupper($tenant);
        return new F33SyntheticRevocationEvent(
            $this->org($tenant),
            $id,
            $kind,
            ($kind === 'session' ? 'DEMO-SESSION-' : 'DEMO-USER-').$suffix,
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

    private function runner(?PDO $ledger = null): F34ReconciliationRunner
    {
        return new F34ReconciliationRunner(
            new F34SyntheticLedgerReader($ledger ?? $this->db('witness','replay')),
            $this->publisher(),
        );
    }

    private function token(string $key, string $tenant): string
    {
        $suffix = strtoupper($tenant);
        return F24LabSessionProof::signFixture([
            'aud' => 'portal-f24-synthetic-read',
            'exp' => 1800000900,
            'iat' => 1800000000,
            'iss' => 'imagine-portal-f24-lab-only',
            'mfa' => 1800000000,
            'mrev' => 1,
            'nonce' => str_repeat('9',32),
            'org' => 'org-'.$tenant,
            'sid' => 'DEMO-SESSION-'.$suffix,
            'srev' => 1,
            'sub' => 'DEMO-USER-'.$suffix,
            'ver' => 1,
        ], $key);
    }

    private function preview(string $tenant)
    {
        $key = random_bytes(32);
        return (new F31RollbackAwareLabReader(
            new F24SyntheticAuthorizedReader($this->db('primary',$tenant),$key),
            new F31ExternalWitnessReader($this->db('witness','reader')),
            $key,
        ))->read($this->org($tenant),$this->token($key,$tenant),self::NOW);
    }

    private function primarySession(string $tenant): array
    {
        $stmt = $this->db('primary','admin')->prepare(
            'SELECT mock_state,session_revision FROM f24.session_fixture
              WHERE organization_id=:org'
        );
        $stmt->execute(['org'=>'org-'.$tenant]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return ['state'=>$row['mock_state'],'revision'=>(int) $row['session_revision']];
    }

    private function resetFixture(): void
    {
        $primary = $this->db('primary','admin');
        $primary->exec("UPDATE f24.session_fixture
                          SET session_revision=1,mock_state='active'");
        $primary->exec("UPDATE f24.membership_fixture
                          SET membership_revision=1,mock_state='active',
                              can_view_services=true");

        $witness = $this->db('witness','admin');
        $witness->exec('DELETE FROM f33.publication_ledger');
        $witness->exec('ALTER TABLE f31.revocation_floor DISABLE TRIGGER f31_floor_monotonic');
        try {
            $witness->exec('UPDATE f31.revocation_floor
                              SET session_floor=1,membership_floor=1,
                                  sequence_number=1,changed_at=1800000000');
        } finally {
            $witness->exec('ALTER TABLE f31.revocation_floor ENABLE TRIGGER f31_floor_monotonic');
        }
    }

    public function test_confirmed_empty_ledger_is_distinct_from_read_failure(): void
    {
        $outcome = $this->runner()->runOnce();
        self::assertTrue($outcome->completed);
        self::assertSame('complete',$outcome->status);
        self::assertSame(0,$outcome->discovered);
        self::assertSame(0,$outcome->applied);
        self::assertTrue($this->preview('a')->isPreparedPreview());
    }

    public function test_replay_finishes_pending_witness_first_session_and_is_idempotent(): void
    {
        $event = $this->event('F33-F34-PENDING-A');
        self::assertSame('published',$this->publisher()->publishWitness($event));
        self::assertSame(['state'=>'active','revision'=>1],$this->primarySession('a'));
        self::assertSame('stale_external_witness',$this->preview('a')->reason);

        $outcome = $this->runner()->runOnce();
        self::assertTrue($outcome->completed);
        self::assertSame(1,$outcome->discovered);
        self::assertSame(1,$outcome->applied);
        self::assertSame(['state'=>'revoked','revision'=>2],$this->primarySession('a'));

        $again = $this->runner()->runOnce();
        self::assertTrue($again->completed);
        self::assertSame(1,$again->applied);
        $seq = $this->db('witness','admin')->query(
            "SELECT sequence_number FROM f31.revocation_floor
              WHERE organization_id='org-a'"
        )->fetchColumn();
        self::assertSame(2,(int)$seq);
    }

    public function test_two_tenant_events_are_replayed_in_deterministic_sequence(): void
    {
        self::assertSame('published',$this->publisher()->publishWitness(
            $this->event('F33-F34-SESSION-A')
        ));
        self::assertSame('published',$this->publisher()->publishWitness(
            $this->event('F33-F34-MEMBERSHIP-B',tenant:'b',kind:'membership')
        ));
        $outcome = $this->runner()->runOnce();
        self::assertTrue($outcome->completed);
        self::assertSame('complete',$outcome->status);
        self::assertSame(2,$outcome->discovered);
        self::assertSame(2,$outcome->applied);
        self::assertSame(['state'=>'revoked','revision'=>2],$this->primarySession('a'));
        self::assertSame(['state'=>'active','revision'=>1],$this->primarySession('b'));
        $b = $this->db('primary','admin')->query(
            "SELECT membership_revision,mock_state
               FROM f24.membership_fixture WHERE organization_id='org-b'"
        )->fetch(PDO::FETCH_ASSOC);
        self::assertSame(2,(int)$b['membership_revision']);
        self::assertSame('revoked',$b['mock_state']);
    }

    public function test_ledger_reader_permission_loss_is_failure_not_false_empty_queue(): void
    {
        self::assertSame('published',$this->publisher()->publishWitness(
            $this->event('F33-F34-READER-REVOKED')
        ));
        $admin = $this->db('witness','admin');
        $admin->exec('REVOKE SELECT ON f33.publication_ledger FROM f34_reconciliation_reader');
        try {
            $outcome = $this->runner()->runOnce();
            self::assertFalse($outcome->completed);
            self::assertSame('ledger_unavailable',$outcome->status);
            self::assertSame(0,$outcome->applied);
            self::assertSame(['state'=>'active','revision'=>1],$this->primarySession('a'));
            self::assertSame('stale_external_witness',$this->preview('a')->reason);
        } finally {
            $admin->exec('GRANT SELECT ON f33.publication_ledger TO f34_reconciliation_reader');
        }
        self::assertTrue($this->runner()->runOnce()->completed);
    }

    public function test_primary_fail_after_one_applied_event_is_partial_not_complete(): void
    {
        self::assertSame('published',$this->publisher()->publishWitness(
            $this->event('F33-F34-PARTIAL-A')
        ));
        self::assertSame('published',$this->publisher()->publishWitness(
            $this->event('F33-F34-PARTIAL-B',tenant:'b')
        ));
        $holder = $this->db('primary','b');
        $holder->query('SELECT pg_catalog.pg_advisory_lock_shared(9402400002)')->closeCursor();
        try {
            $result = $this->runner()->runOnce();
            self::assertFalse($result->completed);
            self::assertSame('primary_apply_pending',$result->status);
            self::assertSame(2,$result->discovered);
            self::assertSame(1,$result->applied);
            self::assertSame(['state'=>'revoked','revision'=>2],$this->primarySession('a'));
            self::assertSame(['state'=>'active','revision'=>1],$this->primarySession('b'));
            self::assertSame('stale_external_witness',$this->preview('b')->reason);
        } finally {
            $holder->query('SELECT pg_catalog.pg_advisory_unlock_shared(9402400002)')->closeCursor();
        }
        $retry=$this->runner()->runOnce();
        self::assertTrue($retry->completed);
        self::assertSame(2,$retry->applied);
        self::assertSame(['state'=>'revoked','revision'=>2],$this->primarySession('b'));
    }

    public function test_reconciliation_role_cannot_modify_ledger_or_witness_floor(): void
    {
        $reader=$this->db('witness','replay');
        foreach ([
            "DELETE FROM f33.publication_ledger",
            "UPDATE f31.revocation_floor SET session_floor=1 WHERE organization_id='org-a'",
            "SELECT f33.publish_revocation('F33-F34-MALICE','org-a','session',
                'DEMO-SESSION-A',2,1,1800000031)",
        ] as $statement) {
            try {
                $reader->exec($statement);
                self::fail('F34 replay-reader role acquired a write capability.');
            } catch (PDOException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame(['state'=>'active','revision'=>1],$this->primarySession('a'));
    }

    public function test_admin_sql_connection_cannot_impersonate_restricted_ledger_reader(): void
    {
        $outcome = $this->runner($this->db('witness','admin'))->runOnce();
        self::assertFalse($outcome->completed);
        self::assertSame('ledger_unavailable',$outcome->status);
    }

    public function test_more_than_twenty_unacknowledged_events_fails_closed_not_truncated(): void
    {
        $admin=$this->db('witness','admin');
        $insert=$admin->prepare(
            'INSERT INTO f33.publication_ledger
             (event_id,organization_id,kind,subject_ref,session_floor,
              membership_floor,changed_at,sequence_number)
             VALUES (:id,:org,:kind,:ref,:sf,:mf,:at,:seq)'
        );
        for ($seq=2; $seq<=22; $seq++) {
            $insert->execute([
                'id'=>'F33-F34-OVERLIMIT-'.$seq,
                'org'=>'org-a',
                'kind'=>'session',
                'ref'=>'DEMO-SESSION-A',
                'sf'=>$seq,
                'mf'=>1,
                'at'=>1800000031,
                'seq'=>$seq,
            ]);
        }
        $outcome=$this->runner()->runOnce();
        self::assertFalse($outcome->completed);
        self::assertSame('ledger_unavailable',$outcome->status);
        self::assertSame(0,$outcome->applied);
        self::assertSame(['state'=>'active','revision'=>1],$this->primarySession('a'));
    }
}
