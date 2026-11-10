<?php

declare(strict_types=1);

/**
 * PORTAL-F102 — synthetic, CLI-only GSC OAuth transaction contract.
 * No Google network calls, credentials, session cookies, token exchange or
 * operational authorization. State lives in memory and is NOT durable.
 */
final class PortalF102OfflineGscOAuth
{
    private array $pending = [];

    private const SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';
    private const UUID = '/\\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\\z/D';

    public function begin(
        string $actorId,
        string $organizationId,
        string $propertyFingerprint,
        string $redirectUri,
        int $now
    ): ?array {
        if (!self::lab() || !self::uuid($actorId) || !self::uuid($organizationId)
            || preg_match('/\\A[a-f0-9]{64}\\z/D', $propertyFingerprint) !== 1
            || !self::redirect($redirectUri) || $now <= 0) {
            return null;
        }
        $state = self::b64(random_bytes(32));
        $verifier = self::b64(random_bytes(32));
        $challenge = self::b64(hash('sha256', $verifier, true));
        $this->pending[hash('sha256', $state)] = [
            'actor' => $actorId, 'organization' => $organizationId,
            'property' => $propertyFingerprint, 'redirect' => $redirectUri,
            'verifier' => $verifier, 'expires' => $now + 300,
        ];
        // Only a synthetic descriptor, never a usable authorization URL.
        return [
            'state' => $state, 'pkceVerifier' => $verifier,
            'pkceChallenge' => $challenge, 'pkceMethod' => 'S256',
            'requiredScope' => self::SCOPE, 'expiresAt' => $now + 300,
            'networkPerformed' => false, 'authorization' => 'HOLD',
        ];
    }

    public function consume(
        string $state,
        string $actorId,
        string $organizationId,
        string $propertyFingerprint,
        string $redirectUri,
        int $now
    ): bool {
        if (!self::lab() || strlen($state) !== 43
            || preg_match('/\\A[A-Za-z0-9_-]{43}\\z/D', $state) !== 1) {
            return false;
        }
        $key = hash('sha256', $state);
        $entry = $this->pending[$key] ?? null;
        // Consume on the first attempt, successful or not: no replay and
        // no cross-tenant brute-force retries against the same state.
        unset($this->pending[$key]);
        if (!is_array($entry) || $now <= 0 || $now > $entry['expires']
            || !self::uuid($actorId) || !self::uuid($organizationId)
            || !hash_equals($entry['actor'], $actorId)
            || !hash_equals($entry['organization'], $organizationId)
            || !hash_equals($entry['property'], $propertyFingerprint)
            || !hash_equals($entry['redirect'], $redirectUri)) {
            return false;
        }
        // A matching transaction is NOT authentication or an OAuth grant.
        return true;
    }

    public static function authorizesGoogleAccess(): bool
    {
        return false;
    }

    public static function authorizesPortalHttp(): bool
    {
        return false;
    }

    private static function lab(): bool
    {
        return PHP_SAPI === 'cli'
            && getenv('APP_ENV') === 'testing'
            && getenv('PORTAL_F102_GSC_LAB') === '1';
    }

    private static function uuid(string $value): bool
    {
        return preg_match(self::UUID, $value) === 1;
    }

    private static function redirect(string $uri): bool
    {
        // Explicit fixed allowlist: no arbitrary callback, fragment, query,
        // credentials, localhost or open-redirect based on tenant input.
        return $uri === 'https://portal.imaginecomunicacao.com.br/oauth/google/callback';
    }

    private static function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
