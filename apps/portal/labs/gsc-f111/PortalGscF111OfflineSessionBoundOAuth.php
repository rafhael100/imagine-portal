<?php

declare(strict_types=1);

require_once __DIR__.'/../gsc-f107/PortalGscF107OfflineVerifierEnvelope.php';

/**
 * F111: session-bound laboratory issue/consume with a DB-side live IAM recheck.
 * Uses real Portal identity table *shapes* with synthetic records, not a trusted login issuer.
 * Even a valid fake grant can NEVER authorize HTTP or Google.
 */
final readonly class PortalGscF111OfflineSessionBoundOAuth
{
    private const UUID = '/\\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\\z/D';

    public function __construct(private PDO $reader)
    {
    }

    public function issue(
        string $sessionBearer,
        string $state,
        string $verifier,
        string $actorId,
        string $organizationId,
        string $propertyFingerprint,
        int $issuedAt
    ): bool {
        if (!$this->ready()
            || !self::opaque($sessionBearer) || !self::opaque($state)
            || preg_match(self::UUID, $actorId) !== 1
            || preg_match(self::UUID, $organizationId) !== 1
            || preg_match('/\\A[a-f0-9]{64}\\z/D', $propertyFingerprint) !== 1
            || $issuedAt < 1 || $issuedAt > 4102444800) {
            return false;
        }

        $digest = hash('sha256', $state);
        $envelope = PortalGscF107OfflineVerifierEnvelope::seal(
            $verifier, $digest, $actorId, $organizationId,
            $propertyFingerprint, $issuedAt
        );
        if (!is_array($envelope)) {
            return false;
        }

        try {
            $stmt = $this->reader->prepare(
                'SELECT public.portal_gsc_f111_issue('
                . ':session_hash,:state_hash,CAST(:actor AS uuid),'
                . 'CAST(:organization AS uuid),:property,'
                . 'CAST(:issued AS bigint),CAST(:expires AS bigint),'
                . ':nonce,:ciphertext)'
            );
            $stmt->execute([
                'session_hash' => hash('sha256', $sessionBearer),
                'state_hash' => $digest,
                'actor' => $actorId,
                'organization' => $organizationId,
                'property' => $propertyFingerprint,
                'issued' => $issuedAt,
                'expires' => $issuedAt + 300,
                'nonce' => $envelope['nonce'],
                'ciphertext' => $envelope['ciphertext'],
            ]);
            return in_array($stmt->fetchColumn(), [true, 1, '1', 't'], true);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Only the private CLI test adapter may recover an encrypted verifier.
     * F111 database callback burns state before checking its session identity
     * and current IAM grants; it never releases ciphertext on denial.
     */
    public function consume(
        string $sessionBearer,
        string $state,
        string $actorId,
        string $organizationId,
        string $propertyFingerprint
    ): ?string {
        if (!$this->ready()
            || !self::opaque($sessionBearer) || !self::opaque($state)
            || preg_match(self::UUID, $actorId) !== 1
            || preg_match(self::UUID, $organizationId) !== 1
            || preg_match('/\\A[a-f0-9]{64}\\z/D', $propertyFingerprint) !== 1) {
            return null;
        }

        $stateDigest = hash('sha256', $state);
        try {
            $stmt = $this->reader->prepare(
                'SELECT matched,envelope_nonce,envelope_ciphertext '
                . 'FROM public.portal_gsc_f111_consume('
                . ':session_hash,:state_hash,CAST(:actor AS uuid),'
                . 'CAST(:organization AS uuid),:property)'
            );
            $stmt->execute([
                'session_hash' => hash('sha256', $sessionBearer),
                'state_hash' => $stateDigest,
                'actor' => $actorId,
                'organization' => $organizationId,
                'property' => $propertyFingerprint,
            ]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($result) || count($result) !== 3
                || !in_array($result['matched'] ?? null, [true, 1, '1', 't'], true)
                || !is_string($result['envelope_nonce'])
                || !is_string($result['envelope_ciphertext'])) {
                return null;
            }

            // No real token exchange: decrypt only within lab CLI.
            return PortalGscF107OfflineVerifierEnvelope::unseal([
                'version' => 'portal-gsc-secretbox-offline-v1',
                'nonce' => $result['envelope_nonce'],
                'ciphertext' => $result['envelope_ciphertext'],
                'persisted' => false,
                'authorization' => 'HOLD',
            ], $stateDigest, $actorId, $organizationId, $propertyFingerprint, time());
        } catch (Throwable) {
            return null;
        }
    }

    public static function authorizesOperationalOAuth(): bool
    {
        return false;
    }

    private function ready(): bool
    {
        if (PHP_SAPI !== 'cli'
            || getenv('APP_ENV') !== 'testing'
            || getenv('PORTAL_F111_PG_LAB') !== '1'
            || getenv('PGHOST') !== '127.0.0.1'
            || getenv('PGPORT') !== '5432'
            || getenv('PGDATABASE') !== 'portal_identity_lab'
            || $this->reader->inTransaction()) {
            return false;
        }

        try {
            return $this->reader->query('SELECT current_user, current_database()')
                ->fetch(PDO::FETCH_NUM) === [
                    'portal_gsc_f106_client', 'portal_identity_lab',
                ];
        } catch (Throwable) {
            return false;
        }
    }

    private static function opaque(string $value): bool
    {
        if (strlen($value) !== 43
            || preg_match('/\\A[A-Za-z0-9_-]{43}\\z/D', $value) !== 1) {
            return false;
        }
        $bytes = base64_decode(strtr($value, '-_', '+/').'=', true);
        return is_string($bytes) && strlen($bytes) === 32
            && rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=') === $value;
    }
}
