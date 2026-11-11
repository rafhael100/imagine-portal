<?php

declare(strict_types=1);

/**
 * F106 — disposable PostgreSQL-backed OAuth state ledger, CLI only.
 *
 * This proves one-time state consumption and actor/organization/property
 * binding against a non-superuser role. There is NO authenticated issuer,
 * OAuth callback, PKCE-verifier persistence or Google access in this lab.
 */
final readonly class PortalGscF106OfflineStateLedger
{
    private const UUID = '/\\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\\z/D';

    public function __construct(private PDO $db)
    {
    }

    public function issue(
        string $state,
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

        try {
            $stmt = $this->db->prepare(
                'SELECT public.portal_gsc_f106_issue('
                . ':digest,CAST(:actor AS uuid),CAST(:organization AS uuid),'
                . ':property,CAST(:issued AS bigint),CAST(:expires AS bigint))'
            );
            $stmt->execute([
                'digest' => hash('sha256', $state),
                'actor' => $actorId,
                'organization' => $organizationId,
                'property' => $propertyFingerprint,
                'issued' => $issuedAt,
                'expires' => $issuedAt + 300,
            ]);
            return self::asBool($stmt->fetchColumn());
        } catch (Throwable) {
            return false;
        }
    }

    public function consume(
        string $state,
        string $actorId,
        string $organizationId,
        string $propertyFingerprint,
        int $now
    ): bool {
        if (!$this->ready()
            || !self::valid($state, $actorId, $organizationId, $propertyFingerprint)
            || $now < 1 || $now > 4102444800) {
            return false;
        }

        try {
            $stmt = $this->db->prepare(
                'SELECT public.portal_gsc_f106_consume('
                . ':digest,CAST(:actor AS uuid),CAST(:organization AS uuid),'
                . ':property,CAST(:now AS bigint))'
            );
            $stmt->execute([
                'digest' => hash('sha256', $state),
                'actor' => $actorId,
                'organization' => $organizationId,
                'property' => $propertyFingerprint,
                'now' => $now,
            ]);
            return self::asBool($stmt->fetchColumn());
        } catch (Throwable) {
            return false;
        }
    }

    public static function authorizesPortalHttp(): bool
    {
        return false;
    }

    public static function authorizesGoogleAccess(): bool
    {
        return false;
    }

    private function ready(): bool
    {
        if (PHP_SAPI !== 'cli'
            || getenv('APP_ENV') !== 'testing'
            || getenv('PORTAL_F106_PG_LAB') !== '1'
            || getenv('PGHOST') !== '127.0.0.1'
            || getenv('PGPORT') !== '5432'
            || getenv('PGDATABASE') !== 'portal_identity_lab'
            || $this->db->inTransaction()) {
            return false;
        }

        try {
            $identity = $this->db
                ->query('SELECT current_user, current_database()')
                ->fetch(PDO::FETCH_NUM);
            return $identity === [
                'portal_gsc_f106_client', 'portal_identity_lab',
            ];
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

    private static function asBool(mixed $value): bool
    {
        return in_array($value, [true, 't', '1', 1], true);
    }
}
