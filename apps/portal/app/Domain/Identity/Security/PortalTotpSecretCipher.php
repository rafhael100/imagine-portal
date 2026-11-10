<?php

declare(strict_types=1);

namespace App\Domain\Identity\Security;

use SodiumException;

/**
 * Local cryptographic primitive only — NOT a secret vault or MFA authority.
 *
 * Envelope: mfa-sb1: + base64url(random nonce || XSalsa20-Poly1305 ciphertext).
 * A separate, versioned 256-bit server key is REQUIRED. No client-supplied
 * key and no production key provisioning exist in the Portal yet.
 */
final class PortalTotpSecretCipher
{
    private const PREFIX = 'mfa-sb1:';
    private const DOMAIN = "IMAGINE-PORTAL-TOTP-SB1\n";

    public static function seal(string $base32Secret, string $key): ?string
    {
        if (!extension_loaded('sodium')
            || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES
            || !self::validSecret($base32Secret)) {
            return null;
        }

        try {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $ciphertext = sodium_crypto_secretbox(self::DOMAIN . $base32Secret, $nonce, $key);
            return self::PREFIX . rtrim(strtr(base64_encode($nonce . $ciphertext), '+/', '-_'), '=');
        } catch (SodiumException) {
            return null;
        }
    }

    public static function open(string $envelope, string $key): ?string
    {
        if (!extension_loaded('sodium')
            || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES
            || !str_starts_with($envelope, self::PREFIX)
            || strlen($envelope) > 256) {
            return null;
        }

        $encoded = substr($envelope, strlen(self::PREFIX));
        if ($encoded === '' || preg_match('/\A[A-Za-z0-9_-]+\z/D', $encoded) !== 1) {
            return null;
        }

        $binary = base64_decode(strtr($encoded, '-_', '+/'), true);
        if (!is_string($binary)
            || rtrim(strtr(base64_encode($binary), '+/', '-_'), '=') !== $encoded
            || strlen($binary) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES + strlen(self::DOMAIN) + 26) {
            return null;
        }

        $nonce = substr($binary, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($binary, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        try {
            $plain = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);
            if (!is_string($plain) || !str_starts_with($plain, self::DOMAIN)) {
                return null;
            }

            $secret = substr($plain, strlen(self::DOMAIN));
            return self::validSecret($secret) ? $secret : null;
        } catch (SodiumException) {
            return null;
        }
    }

    private static function validSecret(string $secret): bool
    {
        return strlen($secret) >= 26
            && strlen($secret) <= 64
            && preg_match('/\A[A-Z2-7]+\z/D', $secret) === 1;
    }

    public static function grantsMfaOrLogin(): bool
    {
        return false;
    }
}
