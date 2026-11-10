<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Identity\Security\PortalTotpSecretCipher;
use PHPUnit\Framework\TestCase;

final class PortalTotpSecretCipherTest extends TestCase
{
    private const RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    public function test_round_trip_with_ephemeral_key_and_random_nonce(): void
    {
        $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $one = PortalTotpSecretCipher::seal(self::RFC_SECRET, $key);
        $two = PortalTotpSecretCipher::seal(self::RFC_SECRET, $key);
        self::assertIsString($one);
        self::assertIsString($two);
        self::assertStringStartsWith('mfa-sb1:', $one);
        self::assertNotSame($one, $two);
        self::assertStringNotContainsString(self::RFC_SECRET, $one);
        self::assertSame(self::RFC_SECRET, PortalTotpSecretCipher::open($one, $key));
        self::assertSame(self::RFC_SECRET, PortalTotpSecretCipher::open($two, $key));
        sodium_memzero($key);
    }

    public function test_wrong_key_and_tampering_fail_closed(): void
    {
        $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $otherKey = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $sealed = PortalTotpSecretCipher::seal(self::RFC_SECRET, $key);
        self::assertIsString($sealed);
        self::assertNull(PortalTotpSecretCipher::open($sealed, $otherKey));
        self::assertNull(PortalTotpSecretCipher::open('mfa-sb2:' . substr($sealed, 8), $key));
        self::assertNull(PortalTotpSecretCipher::open($sealed . '=', $key));

        $parts = explode(':', $sealed, 2);
        self::assertCount(2, $parts);
        $mutated = substr_replace($parts[1], $parts[1][0] === 'A' ? 'B' : 'A', 0, 1);
        self::assertNull(PortalTotpSecretCipher::open('mfa-sb1:' . $mutated, $key));
        sodium_memzero($key);
        sodium_memzero($otherKey);
    }

    public function test_rejects_invalid_secrets_and_keys(): void
    {
        $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        foreach (['', 'short', strtolower(self::RFC_SECRET), self::RFC_SECRET.'='] as $secret) {
            self::assertNull(PortalTotpSecretCipher::seal($secret, $key));
        }
        self::assertNull(PortalTotpSecretCipher::seal(self::RFC_SECRET, 'incorrect-key'));
        self::assertNull(PortalTotpSecretCipher::open('mfa-sb1:invalid', $key));
        self::assertFalse(PortalTotpSecretCipher::grantsMfaOrLogin());
        sodium_memzero($key);
    }
}
