<?php

declare(strict_types=1);

require_once __DIR__.'/../gsc-f107/PortalGscF107OfflineVerifierEnvelope.php';

/**
 * GSC-F108: joins F106 PostgreSQL single-use state with F107 authenticated
 * encryption. Strictly CLI + synthetic lab. No OAuth callback or HTTP route.
 */
final readonly class PortalGscF108OfflineSealedState
{
    private const UUID = '/\\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\\z/D';

    public function __construct(private PDO $reader)
    {
    }

    public function issue(
        string $state,
        string $verifier,
        string $actorId,
        string $organizationId,
        string $propertyFingerprint,
        int $issuedAt
    ): bool {
        if (!$this->ready()
            || !self::valid($state, $actorId, $organizationId, $propertyFingerprint)
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
                'SELECT public.portal_gsc_f108_issue('
                . ':digest,CAST(:actor AS uuid),CAST(:organization AS uuid),'
                . ':property,CAST(:issued AS bigint),CAST(:expires AS bigint),'
                . ':nonce,:ciphertext)'
            );
            $stmt->execute([
                'digest' => $digest,
                'actor' => $actorId,
                'organization' => $organizationId,
                'property' => $propertyFingerprint,
                'issued' => $issuedAt,
                'expires' => $issuedAt + 300,
                'nonce' => $envelope['nonce'],
                'ciphertext' => $envelope['ciphertext'],
            ]);
            return in_array($stmt->fetchColumn(), [true, 't', '1', 1], true);
        } catch (Throwable) {
            return false;
        }
    }

    /** Only private CLI tests may receive the synthetic verifier. */
    public function consume(
        string $state,
        string $actorId,
        string $organizationId,
        string $propertyFingerprint,
        int $now
    ): ?string {
        if (!$this->ready()
            || !self::valid($state, $actorId, $organizationId, $propertyFingerprint)
            || $now < 1 || $now > 4102444800) {
            return null;
        }

        $digest = hash('sha256', $state);
        try {
            $stmt = $this->reader->prepare(
                'SELECT matched, envelope_nonce, envelope_ciphertext '
                . 'FROM public.portal_gsc_f108_consume('
                . ':digest,CAST(:actor AS uuid),CAST(:organization AS uuid),'
                . ':property,CAST(:now AS bigint))'
            );
            $stmt->execute([
                'digest' => $digest,
                'actor' => $actorId,
                'organization' => $organizationId,
                'property' => $propertyFingerprint,
                'now' => $now,
            ]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row) || count($row) !== 3
                || !in_array($row['matched'] ?? null, [true, 't', '1', 1], true)
                || !is_string($row['envelope_nonce'])
                || !is_string($row['envelope_ciphertext'])) {
                return null;
            }

            // The database never returns plaintext: decryption happens only
            // inside this confidential test adapter after the state is burned.
            return PortalGscF107OfflineVerifierEnvelope::unseal(
                [
                    'version' => 'portal-gsc-secretbox-offline-v1',
                    'nonce' => $row['envelope_nonce'],
                    'ciphertext' => $row['envelope_ciphertext'],
                    'persisted' => false,
                    'authorization' => 'HOLD',
                ],
                $digest, $actorId, $organizationId,
                $propertyFingerprint, $now
            );
        } catch (Throwable) {
            return null;
        }
    }

    public static function authorizesHttp(): bool
    {
        return false;
    }

    public static function authorizesGoogle(): bool
    {
        return false;
    }

    private function ready(): bool
    {
        if (PHP_SAPI !== 'cli'
            || getenv('APP_ENV') !== 'testing'
            || getenv('PORTAL_F108_PG_LAB') !== '1'
            || getenv('PGHOST') !== '127.0.0.1'
            || getenv('PGPORT') !== '5432'
            || getenv('PGDATABASE') !== 'portal_identity_lab'
            || $this->reader->inTransaction()) {
            return false;
        }

        try {
            return $this->reader
                ->query('SELECT current_user, current_database()')
                ->fetch(PDO::FETCH_NUM)
                === ['portal_gsc_f106_client', 'portal_identity_lab'];
        } catch (Throwable) {
            return false;
        }
    }

    private static function valid(
        string $state,
        string $actorId,
        string $organizationId,
        string $propertyFingerprint
    ): bool {
        if (strlen($state) !== 43
            || preg_match('/\\A[A-Za-z0-9_-]{43}\\z/D', $state) !== 1
            || preg_match(self::UUID, $actorId) !== 1
            || preg_match(self::UUID, $organizationId) !== 1
            || preg_match('/\\A[a-f0-9]{64}\\z/D', $propertyFingerprint) !== 1) {
            return false;
        }
        $raw = base64_decode(strtr($state, '-_', '+/').'=', true);
        return is_string($raw)
            && strlen($raw) === 32
            && rtrim(strtr(base64_encode($raw), '+/', '-_'), '=') === $state;
    }
}
