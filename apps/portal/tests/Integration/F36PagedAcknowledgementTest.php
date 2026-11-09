<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Organizations\OrganizationId;
use App\Labs\F33\F33SyntheticRevocationEvent;
use App\Labs\F33\F33WitnessFirstRevocationPublisher;
use App\Labs\F34\F34SyntheticLedgerReader;
use App\Labs\F35\F35PrimaryStateVerifier;
use App\Labs\F35\F35WitnessAcknowledgementWriter;
use App\Labs\F36\F36BoundedPagedAcknowledgementRunner;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * F36: multi-page, bounded witness-ledger reconciliation with all test
 * identities and data confined to two disposable PostgreSQL 18 containers.
 */
final class F36PagedAcknowledgementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('F22_ENABLE_LAB') !== 'NON_OPERATIONAL_F22'
            || getenv('F31_ENABLE_LAB') !== 'INDEPENDENT_DISPOSABLE_WITNESS'
            || getenv('F33_ENABLE_LAB') !== 'WITNESS_FIRST_SYNTHETIC_ONLY'
            || getenv('F34_ENABLE_LAB') !== 'BOUNDED_REPLAY_ONLY'
            || getenv('F35_ENABLE_LAB') !== 'ACKNOWLEDGEMENTS_CI_ONLY'
            || getenv('F36_ENABLE_LAB') !== 'PAGED_REPLAY_ONLY') {
            self::markTestSkipped('F36 requires the two-container disposable PG18 CI lab.');
        }
    }

    protected function tearDown(): void
    {
        if (getenv('F36_ENABLE_LAB') === 'PAGED_REPLAY_ONLY') {
            $this->reset();
        }
        parent::tearDown();
    }

    private function db(string $cluster, string $role): PDO
    {
        $primary = [
            'a' => ['f21_org_a',getenv('F22_PG_PASSWORD_A')],
            'b' => ['f21_org_b',getenv('F22_PG_PASSWORD_B')],
            'admin' => ['postgres',getenv('F22_PG_PASSWORD_ADMIN')],
        ];
        $witness = [
            'publisher' => ['f33_revocation_publisher',getenv('F33_PG_PASSWORD_PUBLISHER')],
            'ledger' => ['f34_reconciliation_reader',getenv('F34_PG_PASSWORD_READER')],
            'ack' => ['f35_acknowledgement_writer',getenv('F35_PG_PASSWORD_ACK')],
            'admin' => ['postgres',getenv('F31_PG_PASSWORD_ADMIN')],
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
            throw new RuntimeException('F36 rejects non-ephemeral SQL endpoints.');
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

    private function publisher(): F33WitnessFirstRevocationPublisher
    {
        return new F33WitnessFirstRevocationPublisher(
            $this->db('witness','publisher'),
            $this->db('primary','admin'),
        );
    }

    private function ledger(): F34SyntheticLedgerReader
    {
        return new F34SyntheticLedgerReader($this->db('witness','ledger'));
    }

    private function runner(): F36BoundedPagedAcknowledgementRunner
    {
        return new F36BoundedPagedAcknowledgementRunner(
            $this->ledger(),
            $this->publisher(),
            new F35PrimaryStateVerifier(
                $this->db('primary','a'),
                $this->db('primary','b'),
            ),
            new F35WitnessAcknowledgementWriter($this->db('witness','ack')),
        );
    }

    private function publishSeries(
        string $tenant,
        int $count,
        string $prefix,
    ): void {
        $publisher=$this->publisher();
        $org=new OrganizationId('org-'.$tenant);
        for($i=1;$i<=$count;$i++){
            $rev=$i+1;
            $id='F33-F36-'.$prefix.'-'.str_pad((string)$i,3,'0',STR_PAD_LEFT);
            $event=new F33SyntheticRevocationEvent(
                $org, $id, 'session',
                'DEMO-SESSION-'.strtoupper($tenant),$rev,1,1800000031,
            );
            self::assertSame('published',$publisher->publishWitness($event));
        }
    }

    private function ackCount(): int
    {
        return (int)$this->db('witness','admin')->query(
            'SELECT count(*) FROM f35.applied_receipt'
        )->fetchColumn();
    }

    private function pendingCount(): int
    {
        return (int)$this->db('witness','admin')->query(
            'SELECT count(*) FROM f33.publication_ledger e
               LEFT JOIN f35.applied_receipt a ON a.event_id=e.event_id
              WHERE a.event_id IS NULL'
        )->fetchColumn();
    }

    private function primaryRevision(string $tenant='a'): int
    {
        $stmt=$this->db('primary','admin')->prepare(
            'SELECT session_revision FROM f24.session_fixture
              WHERE organization_id=:org'
        );
        $stmt->execute(['org'=>'org-'.$tenant]);
        return (int)$stmt->fetchColumn();
    }

    private function reset(): void
    {
        $primary=$this->db('primary','admin');
        $primary->exec("UPDATE f24.session_fixture
                          SET mock_state='active',session_revision=1");
        $primary->exec("UPDATE f24.membership_fixture
                          SET mock_state='active',membership_revision=1,
                              can_view_services=true");
        $witness=$this->db('witness','admin');
        $witness->exec('DELETE FROM f35.applied_receipt');
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

    public function test_paged_mode_never_opens_an_unfiltered_full_ledger(): void
    {
        self::assertNull($this->ledger()->readBatch(pageMode:true));
        $empty=$this->runner()->runOnce();
        self::assertTrue($empty->completed);
        self::assertSame(0,$empty->applied);
    }

    public function test_twenty_five_witness_events_are_reconciled_without_offset_skips(): void
    {
        $this->publishSeries('a',25,'PAGE');
        self::assertSame(25,$this->pendingCount());
        // Original F34/F35 bounded single batch still refuses overflow.
        self::assertNull($this->ledger()->readBatch(onlyUnacknowledged:true));

        $result=$this->runner()->runOnce();
        self::assertTrue($result->completed);
        self::assertSame('complete',$result->status);
        self::assertSame(25,$result->discovered);
        self::assertSame(25,$result->applied);
        self::assertSame(25,$this->ackCount());
        self::assertSame(0,$this->pendingCount());
        self::assertSame(26,$this->primaryRevision());

        $again=$this->runner()->runOnce();
        self::assertTrue($again->completed);
        self::assertSame(0,$again->applied);
    }

    public function test_multiple_tenants_fill_different_pages_and_preserve_order(): void
    {
        $this->publishSeries('a',20,'TENANTA');
        $this->publishSeries('b',3,'TENANTB');
        $result=$this->runner()->runOnce();
        self::assertTrue($result->completed);
        self::assertSame(23,$result->applied);
        self::assertSame(23,$this->ackCount());
        self::assertSame(21,$this->primaryRevision('a'));
        self::assertSame(4,$this->primaryRevision('b'));
    }

    public function test_primary_lock_blocks_later_tenant_page_without_false_completion(): void
    {
        $this->publishSeries('a',20,'BLOCKA');
        $this->publishSeries('b',2,'BLOCKB');
        $pdo=$this->db('primary','b');
        $pdo->query('SELECT pg_catalog.pg_advisory_lock_shared(9402400002)')->closeCursor();
        try {
            $failed=$this->runner()->runOnce();
            self::assertFalse($failed->completed);
            self::assertSame('primary_apply_pending',$failed->status);
            self::assertSame(20,$failed->applied);
            self::assertSame(22,$this->pendingCount()+$this->ackCount());
            self::assertSame(20,$this->ackCount());
            self::assertSame(2,$this->pendingCount());
            self::assertSame(21,$this->primaryRevision('a'));
            self::assertSame(1,$this->primaryRevision('b'));
        } finally {
            $pdo->query('SELECT pg_catalog.pg_advisory_unlock_shared(9402400002)')->closeCursor();
        }
        $retried=$this->runner()->runOnce();
        self::assertTrue($retried->completed);
        self::assertSame(2,$retried->applied);
        self::assertSame(22,$this->ackCount());
    }

    public function test_hundred_and_one_events_returns_backpressure_not_false_success(): void
    {
        $this->publishSeries('a',101,'BACKPRESS');
        self::assertSame(101,$this->pendingCount());

        $bounded=$this->runner()->runOnce();
        self::assertFalse($bounded->completed);
        self::assertSame('bounded_backpressure',$bounded->status);
        self::assertSame(100,$bounded->applied);
        self::assertSame(100,$this->ackCount());
        self::assertSame(1,$this->pendingCount());
        self::assertSame(101,$this->primaryRevision());

        $next=$this->runner()->runOnce();
        self::assertTrue($next->completed);
        self::assertSame(1,$next->applied);
        self::assertSame(0,$this->pendingCount());
        self::assertSame(102,$this->primaryRevision());
    }
}
