<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Organizations\OrganizationId;
use App\Domain\RestoreSafety\LabExternalWitnessFloor;
use App\Domain\RestoreSafety\PortalRevocationPublicationProbe;
use App\Domain\RestoreSafety\PortalRevocationReadbackGate;
use App\Domain\RestoreSafety\PortalRevocationReceiptVerifier;
use App\Domain\RestoreSafety\PortalSignedWitnessReader;
use App\Domain\RestoreSafety\PortalWitnessAttestationVerifier;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\DisposableSignedSqliteWitnessAdapter;
use Tests\Support\DisposableSqliteWitnessLedger;

final class DisposableSignedSqliteWitnessAdapterTest extends TestCase
{
    private const GENESIS = '11111111-1111-4111-8111-111111111111';
    private const REVOKE = '22222222-2222-4222-8222-222222222222';
    private const SECOND = '33333333-3333-4333-8333-333333333333';

    private string $dir;
    private string $path;
    private OrganizationId $tenant;
    private string $private;
    private DisposableSqliteWitnessLedger $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = new OrganizationId('demo-org-a');
        $this->private = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
        $this->dir = sys_get_temp_dir().'/portal-witness-sqlite-lab-'.bin2hex(random_bytes(12));
        self::assertTrue(mkdir($this->dir,0700));
        $this->path = $this->dir.'/witness.sqlite';
        $this->ledger = new DisposableSqliteWitnessLedger($this->path);
        $this->ledger->publish(self::GENESIS, new LabExternalWitnessFloor(
            $this->tenant, 'demo-independent-sqlite', 1, 4, 1, 1,
        ));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*')?:[] as $file) { unlink($file); }
        rmdir($this->dir);
        parent::tearDown();
    }

    private function adapter(?DisposableSqliteWitnessLedger $ledger=null,
                             ?string $private=null): DisposableSignedSqliteWitnessAdapter
    {
        return new DisposableSignedSqliteWitnessAdapter(
            $ledger??$this->ledger, $this->tenant, $private??$this->private,
        );
    }

    private function probe(DisposableSignedSqliteWitnessAdapter $adapter,
                           ?string $pub=null): PortalRevocationPublicationProbe
    {
        return new PortalRevocationPublicationProbe(
            $adapter, new PortalRevocationReceiptVerifier(),
            $pub??$adapter->publicKey(),
        );
    }

    private function reader(DisposableSignedSqliteWitnessAdapter $adapter,
                            ?string $pub=null): PortalSignedWitnessReader
    {
        return new PortalSignedWitnessReader(
            $adapter, $pub??$adapter->publicKey(),
            new PortalWitnessAttestationVerifier(),
        );
    }

    private function inspect(DisposableSignedSqliteWitnessAdapter $adapter,
                             string $event=self::REVOKE): \App\Domain\RestoreSafety\PortalRevocationReadbackVerdict
    {
        return (new PortalRevocationReadbackGate())->inspect(
            $this->tenant,$event,'demo-primary-z1',4,2,2,
            $this->probe($adapter), $this->reader($adapter),
        );
    }

    public function test_committed_event_receipt_and_two_live_reads_are_synthetic_only(): void
    {
        $adapter=$this->adapter();
        $result=$this->inspect($adapter);
        self::assertTrue($result->syntheticChecksPassed);
        self::assertSame('synthetic_publication_readback_only',$result->reason);
        self::assertFalse($result->canReleaseServing());
        self::assertFalse($result->canConfirmDurableRevocation());
        self::assertFalse($result->canReissueSessions());

        $reopened=new DisposableSqliteWitnessLedger($this->path);
        $floor=$reopened->event(self::REVOKE,$this->tenant);
        self::assertNotNull($floor);
        self::assertSame(2,$floor->sequence);
        self::assertSame(5,$floor->coveredBackupSequence);
        self::assertSame(2,$floor->sessionFloor);
        self::assertSame(2,$floor->membershipFloor);
        self::assertSame(2,$reopened->observe($this->tenant)?->sequence);
    }

    public function test_after_restart_identical_event_receives_new_nonce_without_new_ledger_event(): void
    {
        $original=$this->adapter();
        self::assertTrue($this->inspect($original)->syntheticChecksPassed);
        $again=$this->adapter(new DisposableSqliteWitnessLedger($this->path));
        self::assertTrue($this->inspect($again)->syntheticChecksPassed);
        self::assertSame(2,$this->ledger->observe($this->tenant)?->sequence);
        self::assertSame(2,$this->ledger->event(self::REVOKE,$this->tenant)?->sequence);
        // Reusing the event ID with higher requested floors does NOT
        // secretly rewrite an already signed old event.
        $incompatible=$this->probe($again)->inspect(
            $this->tenant,self::REVOKE,'demo-primary-z1',4,3,3,
        );
        self::assertNull($incompatible);
        self::assertSame(2,$this->ledger->observe($this->tenant)?->sequence);
    }

    public function test_two_distinct_events_advance_sequentially_without_reusing_prior_event_proof(): void
    {
        $adapter=$this->adapter();
        self::assertTrue($this->inspect($adapter)->syntheticChecksPassed);
        self::assertTrue($this->inspect($adapter,self::SECOND)->syntheticChecksPassed);
        self::assertSame(3,$this->ledger->observe($this->tenant)?->sequence);
        self::assertSame(2,$this->ledger->event(self::REVOKE,$this->tenant)?->sequence);
        self::assertSame(3,$this->ledger->event(self::SECOND,$this->tenant)?->sequence);
        self::assertFalse($this->inspect($adapter,self::SECOND)->canReleaseServing());
    }

    public function test_external_lock_prevents_receipt_until_sqlite_commit_finishes(): void
    {
        $blocking=new PDO('sqlite:'.$this->path,null,null,
            [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $blocking->exec('BEGIN IMMEDIATE');
        try {
            $result=$this->inspect($this->adapter());
            self::assertSame('publication_claim_unavailable',$result->reason);
            self::assertNull($this->ledger->event(self::REVOKE,$this->tenant));
            self::assertFalse($result->canConfirmDurableRevocation());
        } finally {
            $blocking->exec('ROLLBACK');
        }
        self::assertTrue($this->inspect($this->adapter())->syntheticChecksPassed);
    }

    public function test_foreign_tenant_invalid_nonce_and_wrong_public_key_cannot_verify(): void
    {
        $adapter=$this->adapter();
        self::assertNull($adapter->publishForReview(
            new OrganizationId('demo-org-b'),self::REVOKE,4,2,2,
            bin2hex(random_bytes(32)),
        ));
        self::assertNull($adapter->fetchAttestation(
            new OrganizationId('demo-org-b'),bin2hex(random_bytes(32)),
        ));
        self::assertNull($adapter->publishForReview(
            $this->tenant,self::REVOKE,4,2,2,'not-a-nonce',
        ));
        self::assertNull($this->probe($adapter,sodium_crypto_sign_publickey(
            sodium_crypto_sign_keypair()
        ))->inspect($this->tenant,self::REVOKE,'demo-primary-z1',4,2,2));
        self::assertNull($this->reader($adapter,'bad-key')->observe($this->tenant));
        self::assertFalse($this->inspect($adapter)->canAuthorizeHttp());
    }

    public function test_same_runner_file_and_private_key_do_not_establish_real_rollback_safety(): void
    {
        // The very SAME ephemeral key can be carried with another SQLite
        // instance with older history. A valid signature is not proof that
        // this file was independently hosted or cannot be snapshotted.
        $old=$this->adapter();
        self::assertTrue($this->inspect($old)->syntheticChecksPassed);
        $otherDir=sys_get_temp_dir().'/portal-witness-sqlite-lab-'.bin2hex(random_bytes(12));
        self::assertTrue(mkdir($otherDir,0700));
        try {
            $oldLedger=new DisposableSqliteWitnessLedger($otherDir.'/old.sqlite');
            $oldLedger->publish(self::GENESIS,new LabExternalWitnessFloor(
                $this->tenant,'demo-independent-sqlite',1,4,1,1,
            ));
            $restarted=$this->adapter($oldLedger); // same ephemeral key
            $replayed=$this->inspect($restarted);
            self::assertTrue($replayed->syntheticChecksPassed);
            self::assertFalse($replayed->canReleaseServing());
            self::assertFalse($replayed->canConfirmDurableRevocation());
        } finally {
            foreach (glob($otherDir.'/*')?:[] as $file) { unlink($file); }
            rmdir($otherDir);
        }
    }
}
