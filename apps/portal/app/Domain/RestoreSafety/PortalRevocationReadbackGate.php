<?php

declare(strict_types=1);

namespace App\Domain\RestoreSafety;

use App\Domain\Organizations\OrganizationId;
use Throwable;

/**
 * Read-after-publication verification for a synthetic signed witness.
 *
 * The publication is checked against the requested event; two *fresh*
 * signed reads must then agree, cover the publication and not regress.
 * This is an OFFLINE safety test. A restored signing authority can still
 * satisfy it, so the verdict can never grant a session or unlock serving.
 */
final readonly class PortalRevocationReadbackGate
{
    public function inspect(
        OrganizationId $tenant,
        string $eventId,
        string $primaryClusterId,
        int $primaryBackupSequence,
        int $minimumSessionFloor,
        int $minimumMembershipFloor,
        ?PortalRevocationPublicationProbe $publication,
        ?LabExternalWitnessReader $reader,
    ): PortalRevocationReadbackVerdict {
        if ($publication === null || $reader === null) {
            return PortalRevocationReadbackVerdict::blocked('missing_witness_dependency');
        }
        try {
            $claim = $publication->inspect(
                $tenant, $eventId, $primaryClusterId,
                $primaryBackupSequence, $minimumSessionFloor,
                $minimumMembershipFloor,
            );
            if ($claim === null) {
                return PortalRevocationReadbackVerdict::blocked('publication_claim_unavailable');
            }
            $first = $reader->observe($tenant);
            if ($first === null) {
                return PortalRevocationReadbackVerdict::blocked('witness_readback_unavailable');
            }
            $second = $reader->observe($tenant);
            if ($second === null) {
                return PortalRevocationReadbackVerdict::blocked('witness_readback_unavailable');
            }
            if (!$first->organization->equals($tenant)
                || !$second->organization->equals($tenant)
                || !$first->matches($second)) {
                return PortalRevocationReadbackVerdict::blocked('readback_changed_during_check');
            }
            $published = $claim->floor;
            if ($first->witnessClusterId !== $published->witnessClusterId
                || $first->witnessClusterId === $primaryClusterId
                || $first->sequence < $published->sequence
                || $first->coveredBackupSequence < $published->coveredBackupSequence
                || $first->coveredBackupSequence <= $primaryBackupSequence
                || $first->sessionFloor < $published->sessionFloor
                || $first->sessionFloor < $minimumSessionFloor
                || $first->membershipFloor < $published->membershipFloor
                || $first->membershipFloor < $minimumMembershipFloor) {
                return PortalRevocationReadbackVerdict::blocked('readback_not_at_published_floor');
            }

            return PortalRevocationReadbackVerdict::candidate();
        } catch (Throwable) {
            return PortalRevocationReadbackVerdict::blocked('readback_unavailable');
        }
    }
}
