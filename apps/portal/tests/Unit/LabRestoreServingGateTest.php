<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Organizations\OrganizationId;
use App\Domain\RestoreSafety\LabExternalWitnessFloor;
use App\Domain\RestoreSafety\LabQuarantineEvidence;
use App\Domain\RestoreSafety\LabRestoreFenceVerdict;
use App\Domain\RestoreSafety\LabRestoreServingGate;
use App\Domain\RestoreSafety\LabRestoreSnapshot;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class LabRestoreServingGateTest extends TestCase
{
    private function snapshot(
        string $tenant = 'demo-org-a', string $cluster = 'demo-primary-z1',
        int $sequence = 4, int $sessionRev = 1, int $membershipRev = 1,
        bool $sessionActive = false, bool $membershipActive = false,
    ): LabRestoreSnapshot {
        return new LabRestoreSnapshot(
            new OrganizationId($tenant), $cluster, $sequence,
            $sessionRev, $membershipRev, $sessionActive, $membershipActive
        );
    }

    private function witness(
        string $tenant = 'demo-org-a', string $cluster = 'demo-witness-z4',
        int $sequence = 5, int $sessionFloor = 2, int $membershipFloor = 2,
    ): LabExternalWitnessFloor {
        return new LabExternalWitnessFloor(
            new OrganizationId($tenant), $cluster, $sequence,
            $sessionFloor, $membershipFloor
        );
    }

    private function evidence(
        bool $http = true, bool $jobs = true, bool $sessions = true,
        bool $mfa = true, bool $credentials = true, bool $grants = true,
        bool $cache = true,
    ): LabQuarantineEvidence {
        return new LabQuarantineEvidence($http, $jobs, $sessions, $mfa, $credentials, $grants, $cache);
    }

    private function check(
        ?LabRestoreSnapshot $snapshot = null,
        ?LabExternalWitnessFloor $before = null,
        ?LabExternalWitnessFloor $after = null,
        ?LabQuarantineEvidence $evidence = null,
    ): LabRestoreFenceVerdict {
        return (new LabRestoreServingGate())->inspect(
            $snapshot ?? $this->snapshot(),
            $before ?? $this->witness(),
            $after ?? $this->witness(),
            $evidence ?? $this->evidence()
        );
    }

    public function test_only_disposable_coherent_quarantined_fixture_reaches_a_non_operational_candidate(): void
    {
        $decision = $this->check();
        self::assertTrue($decision->syntheticChecksPassed);
        self::assertSame('synthetic_recovery_candidate_only', $decision->reason);
        self::assertFalse($decision->canAuthorizeHttp());
        self::assertFalse($decision->canReleaseProductionServing());
        self::assertFalse($decision->canReissueCustomerSessions());
        self::assertTrue((new \ReflectionClass(LabRestoreFenceVerdict::class))->isReadOnly());
    }

    public function test_missing_readings_and_evidence_always_deny(): void
    {
        $gate = new LabRestoreServingGate();
        foreach ([
            [null, $this->witness(), $this->witness(), $this->evidence()],
            [$this->snapshot(), null, $this->witness(), $this->evidence()],
            [$this->snapshot(), $this->witness(), null, $this->evidence()],
            [$this->snapshot(), $this->witness(), $this->witness(), null],
        ] as [$snapshot, $before, $after, $evidence]) {
            $result = $gate->inspect($snapshot, $before, $after, $evidence);
            self::assertFalse($result->syntheticChecksPassed);
            self::assertFalse($result->canAuthorizeHttp());
            self::assertFalse($result->canReleaseProductionServing());
        }
    }

    public function test_restore_before_revocation_with_live_old_session_is_denied_even_if_witness_advanced(): void
    {
        $result = $this->check(snapshot: $this->snapshot(sessionActive: true));
        self::assertSame('restored_identity_still_active', $result->reason);
        self::assertFalse($result->syntheticChecksPassed);
        self::assertFalse($result->canReissueCustomerSessions());
        $result = $this->check(snapshot: $this->snapshot(membershipActive: true));
        self::assertSame('restored_identity_still_active', $result->reason);
    }

    public function test_restoring_both_database_and_fixture_witness_sequence_does_not_count_as_newer_evidence(): void
    {
        foreach ([1, 3, 4] as $sequence) {
            $result = $this->check(
                before: $this->witness(sequence: $sequence),
                after: $this->witness(sequence: $sequence)
            );
            self::assertSame('witness_not_newer_than_backup', $result->reason);
            self::assertFalse($result->canAuthorizeHttp());
        }
    }

    public function test_matching_old_session_or_membership_revision_cannot_bypass_witness_floor(): void
    {
        foreach ([[1, 2], [2, 1], [1, 1]] as [$sessionFloor, $membershipFloor]) {
            $result = $this->check(
                before: $this->witness(sessionFloor: $sessionFloor, membershipFloor: $membershipFloor),
                after: $this->witness(sessionFloor: $sessionFloor, membershipFloor: $membershipFloor)
            );
            self::assertSame('old_identity_not_fenced', $result->reason);
            self::assertFalse($result->syntheticChecksPassed);
        }
    }

    public function test_foreign_tenant_or_same_primary_cluster_is_not_an_independent_witness(): void
    {
        $foreign = $this->witness(tenant: 'demo-org-b');
        self::assertSame('foreign_witness_organization',
            $this->check(before: $foreign, after: $foreign)->reason);
        $same = $this->witness(cluster: 'demo-primary-z1');
        self::assertSame('witness_not_separated_even_in_fixture',
            $this->check(before: $same, after: $same)->reason);
        self::assertSame('foreign_witness_organization',
            $this->check(snapshot: $this->snapshot(tenant: 'demo-org-b'))->reason);
    }

    public function test_witness_changes_between_readings_always_deny_even_if_both_are_newer(): void
    {
        foreach ([
            $this->witness(sequence: 6),
            $this->witness(sessionFloor: 3),
            $this->witness(membershipFloor: 3),
            $this->witness(cluster: 'demo-witness-other'),
        ] as $after) {
            $decision = $this->check(after: $after);
            self::assertSame('witness_changed_during_rehearsal', $decision->reason);
            self::assertFalse($decision->canReleaseProductionServing());
        }
    }

    public function test_each_individual_quarantine_requirement_is_mandatory(): void
    {
        for ($index = 0; $index < 7; $index++) {
            $claims = array_fill(0, 7, true);
            $claims[$index] = false;
            $evidence = new LabQuarantineEvidence(...$claims);
            self::assertFalse($evidence->allClaimsPresent());
            $decision = $this->check(evidence: $evidence);
            self::assertSame('incomplete_quarantine_rehearsal', $decision->reason);
            self::assertFalse($decision->canAuthorizeHttp());
        }
    }

    public function test_malformed_identifiers_and_revision_bounds_fail_at_creation(): void
    {
        foreach ([
            fn () => $this->snapshot(cluster: '../untrusted'),
            fn () => $this->snapshot(sequence: 0),
            fn () => $this->snapshot(sessionRev: -1),
            fn () => $this->snapshot(membershipRev: 1_000_000_001),
            fn () => $this->witness(cluster: 'bad cluster'),
            fn () => $this->witness(sequence: 0),
            fn () => $this->witness(sessionFloor: 0),
            fn () => $this->witness(membershipFloor: 1_000_000_001),
        ] as $make) {
            try {
                $make();
                self::fail('Accepted an invalid restore-rehearsal fixture.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_forged_quarantine_flags_are_never_customer_authority(): void
    {
        // Even an adversary who supplies all true flags and plausible witness
        // fields cannot acquire a production release capability here.
        $result = $this->check();
        for ($i = 0; $i < 10; $i++) {
            self::assertFalse($result->canAuthorizeHttp());
            self::assertFalse($result->canReleaseProductionServing());
            self::assertFalse($result->canReissueCustomerSessions());
        }
    }
}
