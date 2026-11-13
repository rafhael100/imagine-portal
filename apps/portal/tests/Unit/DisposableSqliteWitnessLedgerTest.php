<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Organizations\OrganizationId;
use App\Domain\RestoreSafety\LabExternalWitnessFloor;
use App\Domain\RestoreSafety\LabQuarantineEvidence;
use App\Domain\RestoreSafety\LabRestoreServingGate;
use App\Domain\RestoreSafety\LabRestoreSnapshot;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\DisposableSqliteWitnessLedger;

final class DisposableSqliteWitnessLedgerTest extends TestCase
{
    private const GENESIS = '11111111-1111-4111-8111-111111111111';
    private const REVOKED = '22222222-2222-4222-8222-222222222222';
    private const OTHER = '33333333-3333-4333-8333-333333333333';

    private string $dir;
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        self::assertTrue(extension_loaded('pdo_sqlite'));
        $this->dir = sys_get_temp_dir() . '/portal-witness-sqlite-lab-' . bin2hex(random_bytes(12));
        self::assertTrue(mkdir($this->dir,0700));
        $this->path = $this->dir . '/witness.sqlite';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($this->dir);
        parent::tearDown();
    }

    private function floor(int $sequence = 1, int $backup = 4, int $session = 1,
                           int $member = 1, string $tenant='demo-org-a'): LabExternalWitnessFloor
    {
        return new LabExternalWitnessFloor(
            new OrganizationId($tenant), 'demo-independent-sqlite',
            $sequence, $backup, $session, $member,
        );
    }

    private function db(): DisposableSqliteWitnessLedger
    {
        return new DisposableSqliteWitnessLedger($this->path);
    }

    private function evidence(): LabQuarantineEvidence
    {
        return new LabQuarantineEvidence(true,true,true,true,true,true,true);
    }

    private function restored(bool $active=false): LabRestoreSnapshot
    {
        return new LabRestoreSnapshot(
            new OrganizationId('demo-org-a'),'demo-primary-z1',4,1,1,$active,false,
        );
    }

    private function rejected(callable $callback): void
    {
        try {
            $callback();
            self::fail('Synthetic witness accepted unsafe or regressive operation.');
        } catch (RuntimeException $error) {
            self::assertSame('Disposable witness event unavailable.',$error->getMessage());
        }
    }

    public function test_independent_sqlite_file_retains_floor_after_reader_reopens(): void
    {
        $writer=$this->db();
        $writer->publish(self::GENESIS,$this->floor());
        $writer->publish(self::REVOKED,$this->floor(2,5,2,2));

        $reopened=$this->db();
        $observed=$reopened->observe(new OrganizationId('demo-org-a'));
        self::assertNotNull($observed);
        self::assertSame(2,$observed->sequence);
        self::assertSame(5,$observed->coveredBackupSequence);
        self::assertSame(2,$observed->sessionFloor);

        // The primary is 'restored' to the earlier generation; its simulated
        // state is not used as the authority for the separate witness file.
        $gate=(new LabRestoreServingGate())->inspectFromReader(
            $this->restored(),$reopened,$this->evidence(),
        );
        self::assertTrue($gate->syntheticChecksPassed);
        self::assertFalse($gate->canAuthorizeHttp());
        self::assertFalse($gate->canReleaseProductionServing());

        $stillActive=(new LabRestoreServingGate())->inspectFromReader(
            $this->restored(true),$reopened,$this->evidence(),
        );
        self::assertSame('restored_identity_still_active',$stillActive->reason);
        self::assertFalse($stillActive->canReissueCustomerSessions());
    }

    public function test_same_signed_event_is_idempotent_but_never_reassigned_to_a_different_scope(): void
    {
        $db=$this->db();
        $db->publish(self::GENESIS,$this->floor());
        $db->publish(self::GENESIS,$this->floor());
        self::assertSame(1,$db->observe(new OrganizationId('demo-org-a'))?->sequence);
        $this->rejected(fn()=> $db->publish(self::GENESIS,$this->floor(1,5,2,2)));
        $this->rejected(fn()=> $db->publish(self::GENESIS,$this->floor(
            1,4,1,1,'demo-org-b'
        )));
        $db->publish(self::REVOKED,$this->floor(2,5,2,2));
        self::assertSame(2,$db->observe(new OrganizationId('demo-org-a'))?->sequence);
    }

    public function test_monotonic_generation_and_floors_cannot_be_skipped_or_decreased(): void
    {
        $db=$this->db();
        $this->rejected(fn()=> $db->publish(self::REVOKED,$this->floor(2,5,2,2)));
        $db->publish(self::GENESIS,$this->floor());
        foreach ([
            $this->floor(1,5,2,2),
            $this->floor(3,5,2,2),
            $this->floor(2,3,2,2),
        ] as $wrong) {
            $this->rejected(fn()=> $db->publish(self::REVOKED,$wrong));
        }
        $db->publish(self::REVOKED,$this->floor(2,5,2,2));
        foreach ([
            $this->floor(2,6,3,3),
            $this->floor(4,6,3,3),
            $this->floor(3,4,3,3),
            $this->floor(3,6,1,3),
            $this->floor(3,6,3,1),
        ] as $wrong) {
            $this->rejected(fn()=> $db->publish(self::OTHER,$wrong));
        }
        self::assertSame(2,$this->db()->observe(new OrganizationId('demo-org-a'))?->sequence);
    }

    public function test_unique_event_is_global_but_each_tenant_has_separate_floor(): void
    {
        $db=$this->db();
        $db->publish(self::GENESIS,$this->floor());
        $this->rejected(fn()=> $db->publish(
            self::GENESIS,$this->floor(1,4,1,1,'demo-org-b')
        ));
        $db->publish(self::OTHER,$this->floor(1,4,1,1,'demo-org-b'));
        self::assertSame(1,$db->observe(new OrganizationId('demo-org-b'))?->sequence);
        self::assertNull($db->observe(new OrganizationId('demo-org-c')));
        self::assertSame(1,$db->observe(new OrganizationId('demo-org-a'))?->sequence);
    }

    public function test_rows_cannot_be_updated_or_deleted_even_using_raw_sql(): void
    {
        $db=$this->db();
        $db->publish(self::GENESIS,$this->floor());
        $conn=new PDO('sqlite:'.$this->path,null,null,[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION
        ]);
        foreach ([
            'UPDATE witness_events SET session_floor=0',
            'DELETE FROM witness_events',
        ] as $sql) {
            try {
                $conn->exec($sql);
                self::fail('Fixture DB allowed mutation of historic event.');
            } catch (PDOException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame(1,$this->db()->observe(new OrganizationId('demo-org-a'))?->sequence);
    }

    public function test_concurrent_transaction_lock_fails_closed_then_retry_commits(): void
    {
        $db=$this->db();
        $db->publish(self::GENESIS,$this->floor());
        $blocker = new PDO('sqlite:'.$this->path,null,null,[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        ]);
        $blocker->exec('BEGIN IMMEDIATE');
        try {
            $competing=$this->db();
            $this->rejected(fn()=> $competing->publish(
                self::REVOKED,$this->floor(2,5,2,2)
            ));
        } finally {
            $blocker->exec('ROLLBACK');
        }
        $db->publish(self::REVOKED,$this->floor(2,5,2,2));
        self::assertSame(2,$this->db()->observe(new OrganizationId('demo-org-a'))?->sequence);
    }

    public function test_non_fixture_file_paths_and_bad_event_id_are_refused(): void
    {
        try {
            new DisposableSqliteWitnessLedger($this->dir.'/../not-allowed.sqlite');
            self::fail('Unsafe path accepted.');
        } catch (RuntimeException $error) {
            self::assertSame('Disposable SQLite witness unavailable.',$error->getMessage());
        }
        $db=$this->db();
        try {
            $db->publish('random-user-id',$this->floor());
            self::fail('Malformed event ID accepted.');
        } catch (RuntimeException $error) {
            self::assertSame('Disposable witness event refused.',$error->getMessage());
        }
    }

    public function test_independence_is_only_a_deployment_assumption_not_crypto_proof(): void
    {
        $db=$this->db();
        $db->publish(self::GENESIS,$this->floor());
        // A second state file restored to an early floor can still be
        // opened and queried. The fixture cannot prove physical separation,
        // immutable snapshots, fsync on remote storage, or backup policies.
        $otherDir=sys_get_temp_dir().'/portal-witness-sqlite-lab-'.bin2hex(random_bytes(12));
        self::assertTrue(mkdir($otherDir,0700));
        try {
            $other=new DisposableSqliteWitnessLedger($otherDir.'/old.sqlite');
            $other->publish(self::OTHER,$this->floor());
            self::assertSame(1,$other->observe(new OrganizationId('demo-org-a'))?->sequence);
            self::assertSame('witness_does_not_cover_backup',
                (new LabRestoreServingGate())->inspectFromReader(
                    $this->restored(),$other,$this->evidence()
                )->reason
            );
        } finally {
            foreach (glob($otherDir.'/*')?:[] as $file) { unlink($file); }
            rmdir($otherDir);
        }
    }
}
