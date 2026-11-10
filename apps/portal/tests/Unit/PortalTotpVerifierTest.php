<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Identity\Security\PortalTotpVerifier;
use PHPUnit\Framework\TestCase;

final class PortalTotpVerifierTest extends TestCase
{
    // RFC 6238 SHA-1 vector secret ("12345678901234567890"), base32 uppercase.
    private const RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    public function test_sha1_six_digit_codes_match_rfc6238_test_vectors(): void
    {
        foreach ([
            [59, '287082'],
            [1111111109, '081804'],
            [1111111111, '050471'],
            [1234567890, '005924'],
            [2000000000, '279037'],
        ] as [$epoch, $code]) {
            self::assertSame(
                intdiv($epoch, 30),
                PortalTotpVerifier::matchCounter(self::RFC_SECRET, $code, $epoch),
            );
        }
    }

    public function test_replaying_the_same_30_second_counter_is_refused(): void
    {
        self::assertSame(1, PortalTotpVerifier::matchCounter(self::RFC_SECRET, '287082', 59));
        self::assertSame(1, PortalTotpVerifier::matchCounter(self::RFC_SECRET, '287082', 60));

        // Caller MUST save this counter with an atomic compare-and-swap.
        self::assertNull(PortalTotpVerifier::matchCounter(self::RFC_SECRET, '287082', 60, 1));
        self::assertNull(PortalTotpVerifier::matchCounter(self::RFC_SECRET, '287082', 60, 2));
    }

    public function test_outside_plus_minus_one_step_window_fails_closed(): void
    {
        self::assertNull(PortalTotpVerifier::matchCounter(self::RFC_SECRET, '287082', 122));
        self::assertNull(PortalTotpVerifier::matchCounter(self::RFC_SECRET, '279037', 59));
    }

    public function test_invalid_secret_code_and_clock_are_rejected(): void
    {
        foreach ([
            ['', '287082', 59],
            ['short', '287082', 59],
            [strtolower(self::RFC_SECRET), '287082', 59],
            [self::RFC_SECRET . '=', '287082', 59],
            [self::RFC_SECRET, '28708', 59],
            [self::RFC_SECRET, '287082 ', 59],
            [self::RFC_SECRET, '28a082', 59],
            [self::RFC_SECRET, '287082', 0],
            [self::RFC_SECRET, '287082', 4102444801],
        ] as [$secret, $code, $time]) {
            self::assertNull(PortalTotpVerifier::matchCounter($secret, $code, $time));
        }
        self::assertNull(PortalTotpVerifier::matchCounter(self::RFC_SECRET, '287082', 59, -2));
    }

    public function test_totp_match_does_not_expose_any_http_or_authorization_capability(): void
    {
        self::assertFalse(method_exists(PortalTotpVerifier::class, 'issueSession'));
        self::assertFalse(method_exists(PortalTotpVerifier::class, 'authorizeOrganization'));
        self::assertFalse(method_exists(PortalTotpVerifier::class, 'markMfaConfirmed'));
    }
}
