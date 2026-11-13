<?php

declare(strict_types=1);

namespace App\Domain\Identity\Security;

use SensitiveParameter;

/**
 * First-party cryptographic primitive for invitation email possession proofs.
 *
 * No email transport, persistent issuer, HTTP route, authorization or account
 * activation is provided by this class. A trusted caller must independently
 * verify the email destination and atomically persist the returned verifier
 * with a TTL and one-use / bounded-attempt state.
 *
 * The verifier binds the 256-bit bearer to one identity, tenant, normalized
 * email and security revision. Reusing a verifier for a different scope,
 * even after copying database rows, must not succeed.
 */
final class PortalEmailProof
{
    private const DOMAIN = "IMAGINE-PORTAL-EMAIL-PROOF-v1\0";
    private const MAX_REVISION = 9223372036854775807;

    /**
     * @return array{token:string,digest:string}|null
     */
    public static function issue(
        string $identityId,
        string $organizationId,
        string $emailNormalized,
        int $securityRevision,
        #[SensitiveParameter] string $serverKey,
    ): ?array {
        if (!self::validScope($identityId, $organizationId, $emailNormalized, $securityRevision)
            || strlen($serverKey) !== 32) {
            return null;
        }

        $token = bin2hex(random_bytes(32));
        return [
            'token' => $token,
            'digest' => self::digest(
                $token,
                $identityId,
                $organizationId,
                $emailNormalized,
                $securityRevision,
                $serverKey,
            ),
        ];
    }

    public static function matches(
        #[SensitiveParameter] string $token,
        string $persistedDigest,
        string $identityId,
        string $organizationId,
        string $emailNormalized,
        int $securityRevision,
        #[SensitiveParameter] string $serverKey,
    ): bool {
        if (!self::validScope($identityId, $organizationId, $emailNormalized, $securityRevision)
            || strlen($serverKey) !== 32
            || preg_match('/\A[a-f0-9]{64}\z/D', $token) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/D', $persistedDigest) !== 1) {
            return false;
        }

        return hash_equals(
            $persistedDigest,
            self::digest(
                $token,
                $identityId,
                $organizationId,
                $emailNormalized,
                $securityRevision,
                $serverKey,
            ),
        );
    }

    /** This primitive is never an authority for a session, role or tenant. */
    public static function grantsAccess(): bool
    {
        return false;
    }

    private static function digest(
        string $token,
        string $identityId,
        string $organizationId,
        string $emailNormalized,
        int $securityRevision,
        string $serverKey,
    ): string {
        // All scope fields are canonical and NUL-free. Length-coded fields
        // make ambiguities impossible even if a future format expands.
        $fields = [
            $identityId, $organizationId, $emailNormalized,
            (string) $securityRevision, $token,
        ];
        $payload = self::DOMAIN;
        foreach ($fields as $field) {
            $payload .= pack('N', strlen($field)) . $field;
        }
        return hash_hmac('sha256', $payload, $serverKey);
    }

    private static function validScope(
        string $identityId,
        string $organizationId,
        string $emailNormalized,
        int $securityRevision,
    ): bool {
        return self::uuid($identityId)
            && self::uuid($organizationId)
            && $identityId !== $organizationId
            && strlen($emailNormalized) >= 5
            && strlen($emailNormalized) <= 254
            && $emailNormalized === strtolower($emailNormalized)
            && filter_var($emailNormalized, FILTER_VALIDATE_EMAIL) !== false
            && $securityRevision >= 1
            && $securityRevision <= self::MAX_REVISION;
    }

    private static function uuid(string $value): bool
    {
        return preg_match(
            '/\A[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}\z/D',
            $value
        ) === 1;
    }
}
