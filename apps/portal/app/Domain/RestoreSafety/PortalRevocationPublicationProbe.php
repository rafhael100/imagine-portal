<?php

declare(strict_types=1);

namespace App\Domain\RestoreSafety;

use App\Domain\Organizations\OrganizationId;
use SensitiveParameter;
use Throwable;

/**
 * Non-operational probe for an event-bound signed acknowledgement.
 *
 * Never reports a durable revocation or allows a session. The default
 * transport is DENY. A later remote implementation must first establish
 * external monotonic durability, revocation ordering, and readback guarantees.
 */
final readonly class PortalRevocationPublicationProbe
{
    public function __construct(
        private PortalRevocationPublicationTransport $transport,
        private PortalRevocationReceiptVerifier $verifier,
        #[SensitiveParameter] private string $pinnedPublicKey,
    ) {
    }

    public function inspect(
        OrganizationId $tenant,
        string $eventId,
        string $primaryClusterId,
        int $primaryBackupSequence,
        int $minimumSessionFloor,
        int $minimumMembershipFloor,
    ): ?PortalSignedRevocationClaim {
        try {
            if ($primaryBackupSequence < 1 || $primaryBackupSequence >= 1000000000
                || $minimumSessionFloor < 1 || $minimumSessionFloor > 1000000000
                || $minimumMembershipFloor < 1 || $minimumMembershipFloor > 1000000000
                || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._:-]{3,63}\z/D', $primaryClusterId) !== 1
                || strlen($this->pinnedPublicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                return null;
            }
            $nonce = bin2hex(random_bytes(32));
            $raw = $this->transport->publishForReview(
                $tenant, $eventId, $primaryBackupSequence,
                $minimumSessionFloor, $minimumMembershipFloor, $nonce,
            );
            if (!is_string($raw)) {
                return null;
            }
            $claim = $this->verifier->verify(
                $raw, $tenant, $eventId, $nonce, $this->pinnedPublicKey,
            );
            if ($claim === null
                || $claim->floor->witnessClusterId === $primaryClusterId
                || $claim->floor->coveredBackupSequence <= $primaryBackupSequence
                || $claim->floor->sessionFloor < $minimumSessionFloor
                || $claim->floor->membershipFloor < $minimumMembershipFloor) {
                return null;
            }
            return $claim;
        } catch (Throwable) {
            return null;
        }
    }

    public function canConfirmDurableRevocation(): bool
    {
        return false;
    }
}
