<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Identity\Security\PortalPasswordCredential;
use PHPUnit\Framework\TestCase;

final class PortalPasswordCredentialTest extends TestCase
{
    public function test_generates_and_verifies_only_argon2id_hashes(): void
    {
        $password = 'F69-synthetic-password-never-used';
        $hash = PortalPasswordCredential::makeHash($password);
        self::assertIsString($hash);
        self::assertStringStartsWith('$argon2id$', $hash);
        self::assertTrue(PortalPasswordCredential::verify($password, $hash));
        self::assertFalse(PortalPasswordCredential::verify('wrong-password', $hash));
        self::assertFalse(PortalPasswordCredential::verify($password, null));
        self::assertFalse(PortalPasswordCredential::verify($password, 'plaintext'));
        self::assertFalse(PortalPasswordCredential::verify($password, password_hash($password, PASSWORD_BCRYPT)));
    }

    public function test_rejects_short_oversized_or_nul_byte_passwords(): void
    {
        self::assertNull(PortalPasswordCredential::makeHash('short'));
        self::assertNull(PortalPasswordCredential::makeHash(str_repeat('a', 1025)));
        self::assertNull(PortalPasswordCredential::makeHash("testing-secret\0invalid"));

        self::assertFalse(PortalPasswordCredential::verify('short', '$argon2id$invalid'));
        self::assertFalse(PortalPasswordCredential::verify(str_repeat('a', 1025), '$argon2id$invalid'));
    }

    public function test_verification_is_not_an_authorization_grant(): void
    {
        self::assertFalse(method_exists(PortalPasswordCredential::class, 'login'));
        self::assertFalse(method_exists(PortalPasswordCredential::class, 'issueSession'));
        self::assertFalse(method_exists(PortalPasswordCredential::class, 'grantPermission'));
    }
}
