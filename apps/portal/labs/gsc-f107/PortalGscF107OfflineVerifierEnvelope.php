<?php

declare(strict_types=1);

/**
 * GSC-F107: sealed PKCE verifier envelope for synthetic CLI tests only.
 *
 * Authenticated encryption binds the verifier to actor/organization/property,
 * OAuth state digest and its 300-second issuance window. No HTTP, Google API,
 * database persistence, operational authorization or token exchange.
 */
final class PortalGscF107OfflineVerifierEnvelope
{
    private const UUID = '/\\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\\z/D';
    private const VERSION = 'portal-gsc-secretbox-offline-v1';

    public static function seal(
        string $verifier,
        string $stateDigest,
        string $actorId,
        string $organizationId,
        string $propertyFingerprint,
        int $issuedAt
    ): ?array {
        $key = self::testKey();
        if ($key === null
            || !self::validContext($stateDigest, $actorId, $organizationId, $propertyFingerprint)
            || !self::validVerifier($verifier)
            || $issuedAt < 1 || $issuedAt > 4102444800) {
            return null;
        }

        try {
            $plaintext = json_encode([
                'verifier' => $verifier,
                'stateDigest' => $stateDigest,
                'actor' => $actorId,
                'organization' => $organizationId,
                'property' => $propertyFingerprint,
                'issued' => $issuedAt,
                'expires' => $issuedAt + 300,
            ], JSON_THROW_ON_ERROR);
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $key);
            sodium_memzero($plaintext);
            return [
                'version' => self::VERSION,
                'nonce' => self::b64($nonce),
                'ciphertext' => self::b64($ciphertext),
                'persisted' => false,
                'authorization' => 'HOLD',
            ];
        } catch (Throwable) {
            return null;
        }
    }

    public static function unseal(
        mixed $envelope,
        string $stateDigest,
        string $actorId,
        string $organizationId,
        string $propertyFingerprint,
        int $now
    ): ?string {
        $key = self::testKey();
        if ($key === null || !self::validContext($stateDigest, $actorId, $organizationId, $propertyFingerprint)
            || $now < 1 || $now > 4102444800
            || !is_array($envelope) || array_is_list($envelope)
            || count($envelope) !== 5
            || !self::exactFields($envelope, [
                'version', 'nonce', 'ciphertext', 'persisted', 'authorization',
            ])
            || $envelope['version'] !== self::VERSION
            || $envelope['persisted'] !== false
            || $envelope['authorization'] !== 'HOLD'
            || !is_string($envelope['nonce'])
            || !is_string($envelope['ciphertext'])) {
            return null;
        }

        $nonce = self::decode($envelope['nonce']);
        $ciphertext = self::decode($envelope['ciphertext']);
        if ($nonce === null || strlen($nonce) !== SODIUM_CRYPTO_SECRETBOX_NONCEBYTES
            || $ciphertext === null || strlen($ciphertext) < SODIUM_CRYPTO_SECRETBOX_MACBYTES + 43
            || strlen($ciphertext) > 4096) {
            return null;
        }

        try {
            $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);
            if (!is_string($plaintext)) {
                return null;
            }
            $data = json_decode($plaintext, true, 8, JSON_THROW_ON_ERROR);
            sodium_memzero($plaintext);
            if (!is_array($data) || count($data) !== 7
                || !self::exactFields($data, [
                    'verifier', 'stateDigest', 'actor', 'organization',
                    'property', 'issued', 'expires',
                ])
                || !is_string($data['verifier'])
                || !self::validVerifier($data['verifier'])
                || !hash_equals($stateDigest, $data['stateDigest'])
                || !hash_equals($actorId, $data['actor'])
                || !hash_equals($organizationId, $data['organization'])
                || !hash_equals($propertyFingerprint, $data['property'])
                || !is_int($data['issued']) || !is_int($data['expires'])
                || $data['expires'] !== $data['issued'] + 300
                || $now < $data['issued'] || $now > $data['expires']) {
                return null;
            }
            // Only synthetic code in labs/ ever sees this verifier. In a real
            // OAuth backend, the value must remain inside the token-exchange
            // service and must never be serialized into HTTP responses.
            return $data['verifier'];
        } catch (Throwable) {
            return null;
        }
    }

    public static function authorizesGoogleAccess(): bool
    {
        return false;
    }

    private static function validContext(
        string $stateDigest,
        string $actorId,
        string $organizationId,
        string $propertyFingerprint
    ): bool {
        return preg_match('/\\A[a-f0-9]{64}\\z/D', $stateDigest) === 1
            && preg_match('/\\A[a-f0-9]{64}\\z/D', $propertyFingerprint) === 1
            && preg_match(self::UUID, $actorId) === 1
            && preg_match(self::UUID, $organizationId) === 1;
    }

    private static function validVerifier(string $verifier): bool
    {
        return strlen($verifier) >= 43 && strlen($verifier) <= 128
            && preg_match('/\\A[A-Za-z0-9._~-]{43,128}\\z/D', $verifier) === 1;
    }

    private static function testKey(): ?string
    {
        if (PHP_SAPI !== 'cli'
            || getenv('APP_ENV') !== 'testing'
            || getenv('PORTAL_F107_GSC_LAB') !== '1'
            || !function_exists('sodium_crypto_secretbox')) {
            return null;
        }
        $encoded = getenv('PORTAL_F107_KEY_B64');
        if (!is_string($encoded)) {
            return null;
        }
        $key = self::decode($encoded);
        return $key !== null && strlen($key) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES
            ? $key : null;
    }

    private static function exactFields(array $value, array $fields): bool
    {
        return count($value) === count($fields)
            && count(array_diff(array_keys($value), $fields)) === 0;
    }

    private static function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function decode(string $encoded): ?string
    {
        if ($encoded === '' || preg_match('/\\A[A-Za-z0-9_-]+\\z/D', $encoded) !== 1) {
            return null;
        }
        $raw = base64_decode(strtr($encoded, '-_', '+/').str_repeat('=', (4 - strlen($encoded) % 4) % 4), true);
        return is_string($raw) && self::b64($raw) === $encoded ? $raw : null;
    }
}
