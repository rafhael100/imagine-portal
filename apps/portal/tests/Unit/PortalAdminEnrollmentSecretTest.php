<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\PortalAccess\Security\PortalAdminEnrollmentSecret;
use PHPUnit\Framework\TestCase;

final class PortalAdminEnrollmentSecretTest extends TestCase
{
    public function test_base32_rfc4648_vector_for_twenty_byte_totp_key():void
    {
        self::assertSame(
            'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ',
            PortalAdminEnrollmentSecret::base32(
                '12345678901234567890'
            )
        );
    }

    public function test_enrollment_is_offline_and_contains_standard_uri():void
    {
        $content=PortalAdminEnrollmentSecret::enrollmentText(
            'user@example.test','12345678901234567890'
        );
        self::assertStringContainsString(
            'otpauth://totp/Imagine%20Portal%3Auser%40example.test',
            $content
        );
        self::assertStringContainsString(
            'secret=GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ',
            $content
        );
        self::assertStringContainsString('digits=6&period=30',$content);
        self::assertStringNotContainsString('http://',$content);
    }

    public function test_short_secret_is_rejected():void
    {
        $this->expectException(\InvalidArgumentException::class);
        PortalAdminEnrollmentSecret::base32('short');
    }
}
