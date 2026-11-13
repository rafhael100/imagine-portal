<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Organizations\OrganizationId;
use App\Domain\RestoreSafety\LabExternalWitnessFloor;
use App\Domain\RestoreSafety\LabQuarantineEvidence;
use App\Domain\RestoreSafety\LabRestoreServingGate;
use App\Domain\RestoreSafety\LabRestoreSnapshot;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\DisposableWitnessJournal;

final class DisposableWitnessJournalTest extends TestCase
{
    private string $directory;
    private string $path;
    private string $key;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/portal-witness-only-' . bin2hex(random_bytes(12));
        self::assertTrue(mkdir($this->directory, 0700));
        $this->path = $this->directory . '/journal.jsonl';
        $this->key = random_bytes(32);
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
        rmdir($this->directory);
    }

    private function floor(int $sequence = 1, int $coverage = 4,
        int $session = 1, int $membership = 1,
        string $tenant = 'demo-org-a'): LabExternalWitnessFloor
    {
        return new LabExternalWitnessFloor(
            new OrganizationId($tenant), 'demo-independent-file',
            $sequence, $coverage, $session, $membership
        );
    }

    private function snapshot(bool $sessionActive = false): LabRestoreSnapshot
    {
        return new LabRestoreSnapshot(
            new OrganizationId('demo-org-a'), 'demo-primary-z1',
            4, 1, 1, $sessionActive, false
        );
    }

    private function evidence(): LabQuarantineEvidence
    {
        return new LabQuarantineEvidence(true, true, true, true, true, true, true);
    }

    public function test_journal_survives_reader_restart_and_keeps_revocation_outside_restored_snapshot(): void
    {
        $writer = new DisposableWitnessJournal($this->path, $this->key);
        $writer->append($this->floor());
        $writer->append($this->floor(2, 5, 2, 2));

        // A fresh reader of the separate file sees the later floor even if
        // a hypothetical primary database is restored to generation 4.
        $reader = new DisposableWitnessJournal($this->path, $this->key);
        $floor = $reader->observe(new OrganizationId('demo-org-a'));
        self::assertSame(2, $floor?->sequence);
        self::assertSame(5, $floor?->coveredBackupSequence);
        $decision = (new LabRestoreServingGate())->inspectFromReader(
            $this->snapshot(), $reader, $this->evidence()
        );
        self::assertTrue($decision->syntheticChecksPassed);
        self::assertSame('synthetic_recovery_candidate_only', $decision->reason);
        self::assertFalse($decision->canAuthorizeHttp());
        self::assertFalse($decision->canReleaseProductionServing());
        self::assertFalse($decision->canReissueCustomerSessions());
    }

    public function test_old_restored_active_session_is_refused_even_with_new_floor(): void
    {
        $journal = new DisposableWitnessJournal($this->path, $this->key);
        $journal->append($this->floor());
        $journal->append($this->floor(2, 5, 2, 2));
        $decision = (new LabRestoreServingGate())->inspectFromReader(
            $this->snapshot(true), $journal, $this->evidence()
        );
        self::assertSame('restored_identity_still_active', $decision->reason);
        self::assertFalse($decision->canAuthorizeHttp());
    }

    public function test_missing_or_corrupted_external_file_is_fail_closed(): void
    {
        $journal = new DisposableWitnessJournal($this->path, $this->key);
        $gate = new LabRestoreServingGate();
        self::assertSame('external_witness_unavailable',
            $gate->inspectFromReader($this->snapshot(), $journal, $this->evidence())->reason);
        $journal->append($this->floor());
        $journal->append($this->floor(2, 5, 2, 2));
        file_put_contents($this->path, 'corrupted-record');
        self::assertSame('external_witness_unavailable',
            $gate->inspectFromReader($this->snapshot(), $journal, $this->evidence())->reason);
        self::assertFalse($gate->inspectFromReader(
            $this->snapshot(), $journal, $this->evidence()
        )->canReleaseProductionServing());
    }

    public function test_reader_using_wrong_key_or_tenant_never_supplies_valid_witness(): void
    {
        $writer = new DisposableWitnessJournal($this->path, $this->key);
        $writer->append($this->floor());
        $writer->append($this->floor(2, 5, 2, 2));
        $wrongKey = new DisposableWitnessJournal($this->path, random_bytes(32));
        self::assertSame('external_witness_unavailable',
            (new LabRestoreServingGate())->inspectFromReader(
                $this->snapshot(), $wrongKey, $this->evidence()
            )->reason);
        self::assertNull($writer->observe(new OrganizationId('demo-org-b')));
    }

    public function test_regressive_monotonic_floor_and_cross_tenant_append_are_refused(): void
    {
        $journal = new DisposableWitnessJournal($this->path, $this->key);
        $journal->append($this->floor());
        $journal->append($this->floor(2, 5, 2, 2));
        $before = file_get_contents($this->path);
        foreach ([
            $this->floor(2, 6, 3, 3),
            $this->floor(3, 4, 3, 3),
            $this->floor(3, 6, 1, 3),
            $this->floor(3, 6, 3, 1),
            $this->floor(3, 6, 3, 3, 'demo-org-b'),
        ] as $invalid) {
            try {
                $journal->append($invalid);
                self::fail('Accepted a non-monotonic test record.');
            } catch (RuntimeException) {
                self::addToAssertionCount(1);
            }
            self::assertSame($before, file_get_contents($this->path));
        }
    }

    public function test_journal_rollback_to_pre_revocation_copy_remains_blocked_for_newer_backup(): void
    {
        $journal = new DisposableWitnessJournal($this->path, $this->key);
        $journal->append($this->floor());
        $oldFile = file_get_contents($this->path);
        $journal->append($this->floor(2, 5, 2, 2));
        // An attacker may copy back the earlier file. This small lab catches
        // it only because the demanded backup floor is newer: it does not
        // prove the witness itself is rollback-resistant.
        file_put_contents($this->path, $oldFile);
        $decision = (new LabRestoreServingGate())->inspectFromReader(
            $this->snapshot(), $journal, $this->evidence()
        );
        self::assertSame('witness_does_not_cover_backup', $decision->reason);
        self::assertFalse($decision->canReleaseProductionServing());
    }
}
