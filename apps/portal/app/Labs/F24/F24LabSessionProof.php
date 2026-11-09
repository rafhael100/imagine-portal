<?php

declare(strict_types=1);

namespace App\Labs\F24;

use App\Domain\Organizations\OrganizationId;
use PDO;
use Throwable;

/**
 * PORTAL-F24: ephemeral HMAC-authenticated fixture, NOT a customer identity.
 *
 * This is a deliberately isolated bridge experiment, separate from the
 * AUTH-PERF Ed25519 work. There is NO secret management, token issuer, login,
 * API, middleware or ability to authorize a real customer.
 */
final readonly class F24LabSessionProof
{
    /** @param array<string,mixed> $claims */
    private function __construct(private array $claims)
    {
    }

    /**
     * Test fixture factory. Never use as a Portal token issuer. Keys are
     * generated in the disposable runner and must not persist in this repo.
     *
     * @param array<string,mixed> $claims
     */
    public static function signFixture(array $claims, string $ephemeralKey): string
    {
        if (strlen($ephemeralKey) !== 32) {
            throw new \InvalidArgumentException('Synthetic HMAC key must be 32 raw bytes.');
        }
        ksort($claims, SORT_STRING);
        $bytes = json_encode($claims, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $encoded = self::encode($bytes);
        return $encoded.'.'.self::encode(hash_hmac('sha256', $encoded, $ephemeralKey, true));
    }

    public static function verifyFixture(string $token, string $ephemeralKey, int $now): ?self
    {
        if (strlen($ephemeralKey) !== 32 || strlen($token) > 1500 || $now <= 0) {
            return null;
        }
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }
        [$payloadPart, $sigPart] = $parts;
        $payload = self::decode($payloadPart);
        $signature = self::decode($sigPart);
        if ($payload === null || $signature === null || strlen($signature) !== 32
            || !hash_equals(hash_hmac('sha256', $payloadPart, $ephemeralKey, true), $signature)) {
            return null;
        }

        try {
            $claims = json_decode($payload, true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($claims)) {
                return null;
            }
            $ordered = $claims;
            ksort($ordered, SORT_STRING);
            if ($claims !== $ordered
                || json_encode($claims, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) !== $payload) {
                // Refuse duplicate JSON keys, reordered fields and ambiguous encodings.
                return null;
            }
        } catch (Throwable) {
            return null;
        }

        $expected = ['aud','exp','iat','iss','mfa','mrev','nonce','org','sid','srev','sub','ver'];
        if (array_keys($claims) !== $expected
            || $claims['ver'] !== 1
            || $claims['iss'] !== 'imagine-portal-f24-lab-only'
            || $claims['aud'] !== 'portal-f24-synthetic-read'
            || !is_string($claims['org'])
            || !in_array($claims['org'], ['org-a', 'org-b'], true)
            || !is_string($claims['sub'])
            || preg_match('/\ADEMO-USER-[A-Z0-9-]{1,40}\z/D', $claims['sub']) !== 1
            || !is_string($claims['sid'])
            || preg_match('/\ADEMO-SESSION-[A-Z0-9-]{1,40}\z/D', $claims['sid']) !== 1
            || !is_string($claims['nonce'])
            || preg_match('/\A[a-f0-9]{32}\z/D', $claims['nonce']) !== 1
            || !is_int($claims['srev']) || $claims['srev'] < 1 || $claims['srev'] > 1000000
            || !is_int($claims['mrev']) || $claims['mrev'] < 1 || $claims['mrev'] > 1000000
            || !is_int($claims['iat']) || $claims['iat'] <= 0
            || !is_int($claims['exp']) || $claims['exp'] <= $now
            || $claims['iat'] > $now
            || $claims['exp'] <= $claims['iat']
            || $claims['exp'] - $claims['iat'] > 900
            || !is_int($claims['mfa']) || $claims['mfa'] < $claims['iat']
            || $claims['mfa'] > $now || $now - $claims['mfa'] > 600) {
            return null;
        }

        return new self($claims);
    }

    public function selectedOrganization(): OrganizationId
    {
        return new OrganizationId($this->claims['org']);
    }

    /** Synthetic subject string used only to cross-check F1 preview sources. */
    public function syntheticSubjectId(): string
    {
        return $this->claims['sub'];
    }

    /** Only the synthetic session label from a lab-signed fixture. */
    public function syntheticSessionId(): string
    {
        return $this->claims['sid'];
    }

    /**
     * These fixed public identifiers are NOT secrets. Session-level shared
     * lock must be acquired BEFORE starting the REPEATABLE READ snapshot.
     */
    public function syntheticFenceKey(): int
    {
        return match ($this->claims['org']) {
            'org-a' => 9402400001,
            'org-b' => 9402400002,
        };
    }

    /**
     * Must be invoked INSIDE F22's own transaction immediately before the
     * service view read. No separate read-before-transaction race.
     *
     * Still does NOT prevent concurrent revocation after the transaction's
     * REPEATABLE READ snapshot, nor simultaneous whole-DB rollback.
     */
    public function matchesCurrentSyntheticDatabase(PDO $pdo, OrganizationId $organization): bool
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql'
            || !$pdo->inTransaction()
            || !hash_equals($this->claims['org'], $organization->value)) {
            return false;
        }
        try {
            // Check the two additional F24 authorization tables are RLS-forced.
            // This is negative drift detection, not authoritative policy attestation.
            $catalog = $pdo->query(
                "SELECT count(*)::int AS protected_tables
                   FROM pg_catalog.pg_class c
                   JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace
                  WHERE n.nspname='f24'
                    AND c.relname IN ('session_fixture', 'membership_fixture')
                    AND c.relkind='r'
                    AND c.relrowsecurity AND c.relforcerowsecurity"
            )->fetch(PDO::FETCH_ASSOC);
            if (!is_array($catalog)
                || !in_array($catalog['protected_tables'] ?? null, [2, '2'], true)) {
                return false;
            }

            $query = $pdo->prepare(
                'SELECT s.session_revision, s.expires_at, s.mfa_at,
                        s.mock_state AS session_state,
                        m.membership_revision, m.mock_state AS membership_state,
                        m.can_view_services
                   FROM f24.session_fixture s
                   JOIN f24.membership_fixture m
                     ON m.organization_id = s.organization_id AND m.actor_id = s.actor_id
                  WHERE s.organization_id = :org AND s.actor_id = :actor
                    AND s.session_id = :session_id'
            );
            $query->bindValue(':org', $this->claims['org'], PDO::PARAM_STR);
            $query->bindValue(':actor', $this->claims['sub'], PDO::PARAM_STR);
            $query->bindValue(':session_id', $this->claims['sid'], PDO::PARAM_STR);
            $query->execute();
            $rows = $query->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) !== 1) {
                return false;
            }
            $row = $rows[0];
            return $row['session_state'] === 'active'
                && $row['membership_state'] === 'active'
                && in_array($row['can_view_services'], [true, 't', '1', 1], true)
                && (string) $row['session_revision'] === (string) $this->claims['srev']
                && (string) $row['membership_revision'] === (string) $this->claims['mrev']
                && (string) $row['expires_at'] === (string) $this->claims['exp']
                && (string) $row['mfa_at'] === (string) $this->claims['mfa'];
        } catch (Throwable) {
            return false;
        }
    }

    private static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function decode(string $part): ?string
    {
        if ($part === '' || preg_match('/\A[A-Za-z0-9_-]+\z/D', $part) !== 1) {
            return null;
        }
        $decoded = base64_decode(strtr($part, '-_', '+/'), true);
        if (!is_string($decoded) || self::encode($decoded) !== $part) {
            return null;
        }
        return $decoded;
    }
}
