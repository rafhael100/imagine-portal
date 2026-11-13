<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Organizations\OrganizationId;
use App\Domain\RestoreSafety\LabExternalWitnessFloor;
use App\Domain\RestoreSafety\PortalRevocationPublicationTransport;
use App\Domain\RestoreSafety\PortalWitnessChallengeTransport;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/**
 * Offline adapter: commit synthetic event in separate SQLite file FIRST,
 * THEN sign a challenge-bound publication receipt; subsequent signed
 * readback is sourced from the committed file (not an in-memory counter).
 *
 * Both the private key and file are disposable and in the same runner.
 * Never treat this as an independently operated or rollback-proof witness.
 */
final readonly class DisposableSignedSqliteWitnessAdapter implements
    PortalRevocationPublicationTransport,
    PortalWitnessChallengeTransport
{
    public function __construct(
        private DisposableSqliteWitnessLedger $ledger,
        private OrganizationId $configuredTenant,
        #[SensitiveParameter] private string $privateKey,
    ) {
        if (getenv('APP_ENV') !== 'testing'
            || strlen($privateKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new RuntimeException('Disposable signed SQLite witness unavailable.');
        }
    }

    public function publicKey(): string
    {
        return sodium_crypto_sign_publickey_from_secretkey($this->privateKey);
    }

    public function publishForReview(
        OrganizationId $tenant,
        string $eventId,
        int $primaryBackupSequence,
        int $minimumSessionFloor,
        int $minimumMembershipFloor,
        #[SensitiveParameter] string $challenge,
    ): ?string {
        try {
            if (!$tenant->equals($this->configuredTenant)
                || !self::nonce($challenge)
                || $primaryBackupSequence < 1 || $primaryBackupSequence >= 1000000000
                || $minimumSessionFloor < 1 || $minimumSessionFloor > 1000000000
                || $minimumMembershipFloor < 1 || $minimumMembershipFloor > 1000000000) {
                return null;
            }

            $existing = $this->ledger->event($eventId, $tenant);
            if ($existing !== null) {
                $floor = $existing;
            } else {
                $previous = $this->ledger->observe($tenant);
                if ($previous === null || $previous->sequence >= 1000000000) {
                    return null; // a genesis event must be inserted by the test
                }
                $floor = new LabExternalWitnessFloor(
                    $tenant, $previous->witnessClusterId,
                    $previous->sequence + 1,
                    max($previous->coveredBackupSequence, $primaryBackupSequence + 1),
                    max($previous->sessionFloor, $minimumSessionFloor),
                    max($previous->membershipFloor, $minimumMembershipFloor),
                );
                $this->ledger->publish($eventId, $floor);
                // Always re-read committed state: never sign an in-memory
                // intended floor that might have been rolled back.
                $floor = $this->ledger->event($eventId, $tenant);
            }
            if ($floor === null
                || $floor->coveredBackupSequence <= $primaryBackupSequence
                || $floor->sessionFloor < $minimumSessionFloor
                || $floor->membershipFloor < $minimumMembershipFloor) {
                return null;
            }
            return $this->signed([
                'version'=>1,
                'purpose'=>'imagine-portal/revocation-ack/v1',
                'challenge'=>$challenge,
                'eventId'=>$eventId,
                'tenant'=>$tenant->value,
                'cluster'=>$floor->witnessClusterId,
                'sequence'=>$floor->sequence,
                'coveredBackupSequence'=>$floor->coveredBackupSequence,
                'sessionFloor'=>$floor->sessionFloor,
                'membershipFloor'=>$floor->membershipFloor,
            ]);
        } catch (Throwable) {
            return null;
        }
    }

    public function fetchAttestation(
        OrganizationId $organization,
        #[SensitiveParameter] string $challenge,
    ): ?string {
        try {
            if (!$organization->equals($this->configuredTenant) || !self::nonce($challenge)) {
                return null;
            }
            $floor = $this->ledger->observe($organization);
            if ($floor === null) {
                return null;
            }
            return $this->signed([
                'version'=>1,
                'challenge'=>$challenge,
                'tenant'=>$organization->value,
                'cluster'=>$floor->witnessClusterId,
                'sequence'=>$floor->sequence,
                'coveredBackupSequence'=>$floor->coveredBackupSequence,
                'sessionFloor'=>$floor->sessionFloor,
                'membershipFloor'=>$floor->membershipFloor,
            ]);
        } catch (Throwable) {
            return null;
        }
    }

    private function signed(array $payload): string
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        return json_encode([
            'payload'=>$payload,
            'signature'=>base64_encode(sodium_crypto_sign_detached($json, $this->privateKey)),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private static function nonce(string $value): bool
    {
        return preg_match('/\A[a-f0-9]{64}\z/D', $value) === 1;
    }

    public function __debugInfo(): array
    {
        return ['adapter'=>'disposable SQLite witness', 'privateKey'=>'[redacted]'];
    }
}
