<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\PortalAccess\Security\PortalAdminTotp;
use PHPUnit\Framework\TestCase;

final class PortalAdminTotpTest extends TestCase
{
    public function test_rfc6238_sha1_six_digit_vector_matches():void
    {
        // RFC 6238: 8-digit 94287082 at T=59, its 6-digit suffix is 287082.
        self::assertSame(
            '287082',
            PortalAdminTotp::code('12345678901234567890',1)
        );
    }

    public function test_current_and_adjacent_steps_match_but_not_distant():void
    {
        $secret=str_repeat('a',20);
        $hex=bin2hex($secret);
        $now=1773000000;
        $counter=intdiv($now,30);
        $code=PortalAdminTotp::code($secret,$counter);
        self::assertSame($counter,PortalAdminTotp::match($hex,$code,$now));
        self::assertNull(PortalAdminTotp::match($hex,$code,$now+120));
        self::assertNull(PortalAdminTotp::match($hex,'AAAAAA',$now));
        self::assertNull(PortalAdminTotp::match('invalid',$code,$now));
    }

    public function test_non_six_digit_and_wrong_secret_are_rejected():void
    {
        $hex=bin2hex(str_repeat('b',20));
        self::assertNull(PortalAdminTotp::match($hex,'12345',1773000000));
        self::assertNull(PortalAdminTotp::match($hex,'1234567',1773000000));
        self::assertNull(PortalAdminTotp::match($hex,123456,1773000000));
        $this->expectException(\InvalidArgumentException::class);
        PortalAdminTotp::code('short',1);
    }
}
