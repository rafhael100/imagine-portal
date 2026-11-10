<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Identity\Invitations\PortalEmailVerificationSecret;
use App\Domain\Identity\Invitations\PortalInvitationSecret;
use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionObject;

final class PortalEmailVerificationSecretTest extends TestCase
{
    public function test_email_challenge_is_256_bit_canonical_domain_separated_and_single_delivery(): void
    {
        $proof=PortalEmailVerificationSecret::mint();
        $token=$proof->takeDeliverySecretOnce();
        self::assertIsString($token);
        self::assertSame(43,strlen($token));
        self::assertSame(1,preg_match('/\A[A-Za-z0-9_-]{43}\z/D',$token));
        self::assertNull($proof->takeDeliverySecretOnce());
        self::assertSame(64,strlen($proof->digest()));
        self::assertSame(
            hash('sha256',"IMAGINE-PORTAL-EMAIL-VERIFY:v1\0".$token),
            $proof->digest()
        );
        self::assertSame($proof->digest(),PortalEmailVerificationSecret::digestCandidate($token));
        self::assertTrue(PortalEmailVerificationSecret::matchesCandidate($token,$proof->digest()));
        self::assertFalse(PortalEmailVerificationSecret::matchesCandidate($token,str_repeat('a',64)));
        self::assertFalse(PortalEmailVerificationSecret::mayConfirmRealEmailOrAuthenticate());
        self::assertNotSame(PortalInvitationSecret::digestCandidate($token),$proof->digest());

        foreach ([$token.'=',strtoupper($token),'crm-cookie!',str_repeat('x',43),''] as $invalid) {
            if ($invalid === strtoupper($token) && $invalid !== $token) {
                // Uppercase encoding may itself be canonical but represents
                // a DIFFERENT token; it must never match the original proof.
                self::assertFalse(PortalEmailVerificationSecret::matchesCandidate($invalid,$proof->digest()));
            } elseif ($invalid === str_repeat('x',43)) {
                self::assertFalse(PortalEmailVerificationSecret::matchesCandidate($invalid,$proof->digest()));
            } else {
                self::assertFalse(PortalEmailVerificationSecret::matchesCandidate($invalid,$proof->digest()));
            }
        }

        $other=PortalEmailVerificationSecret::mint()->takeDeliverySecretOnce();
        self::assertIsString($other);
        self::assertNotSame($token,$other);
    }

    public function test_debug_export_json_and_instance_reflection_do_not_reveal_secret(): void
    {
        $proof=PortalEmailVerificationSecret::mint();
        $digest=$proof->digest();
        $before=[print_r($proof,true),var_export($proof,true)];
        $token=$proof->takeDeliverySecretOnce();
        self::assertIsString($token);
        foreach ([...$before,print_r($proof,true),var_export($proof,true),
            json_encode($proof,JSON_THROW_ON_ERROR)] as $representation) {
            self::assertStringNotContainsString($token,$representation);
            self::assertStringNotContainsString($digest,$representation);
        }
        self::assertSame('{}',json_encode($proof,JSON_THROW_ON_ERROR));
        self::assertCount(0,array_filter(
            (new ReflectionObject($proof))->getProperties(),
            static fn ($p) => !$p->isStatic()
        ));
    }

    public function test_serialization_and_state_restoration_fail_closed(): void
    {
        $proof=PortalEmailVerificationSecret::mint();
        try {
            serialize($proof);
            self::fail('Secrets must not be serialized');
        } catch (LogicException) {
            self::assertTrue(true);
        }
        try {
            PortalEmailVerificationSecret::__set_state([]);
            self::fail('Secrets must not be restored');
        } catch (LogicException) {
            self::assertTrue(true);
        }
        $this->expectException(LogicException::class);
        (string)$proof;
    }
}
