<?php

declare(strict_types=1);

namespace App\Domain\RestoreSafety;

use Throwable;

/**
 * SEC-R03: bounded OFFLINE proof of fail-closed restore decision rules.
 *
 * This has no provider, SQL, HTTP route, container registration or secret.
 * Passing it does NOT solve independent-witness rollback, cache consistency,
 * publication linearizability, operational review or recovery authorization.
 */
final readonly class LabRestoreServingGate
{
    public function inspectFromReader(
        ?LabRestoreSnapshot $restored,
        ?LabExternalWitnessReader $reader,
        ?LabQuarantineEvidence $evidence,
    ): LabRestoreFenceVerdict {
        if ($restored === null || $reader === null || $evidence === null) {
            return LabRestoreFenceVerdict::blocked('missing_restore_evidence');
        }
        try {
            // Independent observations are mandatory. No cached fallback
            // when the witness is unavailable between two evaluations.
            $before = $reader->observe($restored->organization);
            if ($before === null) {
                return LabRestoreFenceVerdict::blocked('external_witness_unavailable');
            }
            $after = $reader->observe($restored->organization);
            if ($after === null) {
                return LabRestoreFenceVerdict::blocked('external_witness_unavailable');
            }
            return $this->inspect($restored, $before, $after, $evidence);
        } catch (Throwable) {
            // A provider error never converts an offline demo to an allow.
            return LabRestoreFenceVerdict::blocked('external_witness_unavailable');
        }
    }

    public function inspect(
        ?LabRestoreSnapshot $restored,
        ?LabExternalWitnessFloor $before,
        ?LabExternalWitnessFloor $after,
        ?LabQuarantineEvidence $evidence,
    ): LabRestoreFenceVerdict {
        try {
            if ($restored === null || $evidence === null) {
                return LabRestoreFenceVerdict::blocked('missing_restore_evidence');
            }
            if ($before === null || $after === null) {
                return LabRestoreFenceVerdict::blocked('external_witness_unavailable');
            }
            if (!$restored->organization->equals($before->organization)
                || !$restored->organization->equals($after->organization)) {
                return LabRestoreFenceVerdict::blocked('foreign_witness_organization');
            }
            if ($restored->primaryClusterId === $before->witnessClusterId
                || $restored->primaryClusterId === $after->witnessClusterId) {
                return LabRestoreFenceVerdict::blocked('witness_not_separated_even_in_fixture');
            }
            if (!$before->matches($after)) {
                return LabRestoreFenceVerdict::blocked('witness_changed_during_rehearsal');
            }
            // Reading a witness <= backup is not evidence of anti-rollback.
            // Require a strictly later, independent synthetic observation.
            if ($before->sequence <= $restored->backupSequence) {
                return LabRestoreFenceVerdict::blocked('witness_not_newer_than_backup');
            }
            // A restored, previously valid session and membership MUST be
            // below both external revocation floors.
            if ($before->sessionFloor <= $restored->sessionRevision
                || $before->membershipFloor <= $restored->membershipRevision) {
                return LabRestoreFenceVerdict::blocked('old_identity_not_fenced');
            }
            if ($restored->sessionActive || $restored->membershipActive) {
                return LabRestoreFenceVerdict::blocked('restored_identity_still_active');
            }
            if (!$evidence->allClaimsPresent()) {
                return LabRestoreFenceVerdict::blocked('incomplete_quarantine_rehearsal');
            }
            return LabRestoreFenceVerdict::syntheticCandidate();
        } catch (Throwable) {
            // Never disclose witness identity, org name or partial records.
            return LabRestoreFenceVerdict::blocked('restore_rehearsal_unavailable');
        }
    }
}
