<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Identity\Invitations\PortalInvitationSecret;
use LogicException;
use PHPUnit\Framework\TestCase;

final class PortalInvitationSecretTest extends TestCase
{
    public function test_single_reveal_token_is_random_canonical_and_hash_only(): void
    {
        $first=PortalInvitationSecret::mint();
        $second=PortalInvitationSecret::mint();
        $digest=$first->digest();

        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D',$digest);
        self::assertNotSame($digest,$second->digest());
        self::assertFalse(PortalInvitationSecret::mayAuthenticateOrGrantAccess());

        $bearer=$first->takeDeliverySecretOnce();
        self::assertIsString($bearer);
        self::assertSame(43,strlen($bearer));
        self::assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{43}\z/D',$bearer);
        self::assertSame(hash('sha256',$bearer),$digest);
        self::assertSame($digest,PortalInvitationSecret::digestCandidate($bearer));
        self::assertTrue(PortalInvitationSecret::matchesCandidate($bearer,$digest));
        self::assertNull($first->takeDeliverySecretOnce());
        self::assertSame($digest,$first->digest());

        $otherBearer=$second->takeDeliverySecretOnce();
        self::assertIsString($otherBearer);
        self::assertFalse(PortalInvitationSecret::matchesCandidate($otherBearer,$digest));
    }

    public function test_malformed_noncanonical_and_cross_service_tokens_are_rejected(): void
    {
        $token=PortalInvitationSecret::mint();
        $valid=$token->takeDeliverySecretOnce();
        self::assertIsString($valid);

        foreach([
            '', 'CRM-auth-cookie', $valid.'=', $valid.'abc',
            str_replace('-', '+', $valid),
            substr($valid,1),
            str_repeat('?',43),
            str_repeat('a',5000),
            "\x00".$valid,
        ] as $bad) {
            if ($bad === $valid) continue;
            self::assertNull(PortalInvitationSecret::digestCandidate($bad));
            self::assertFalse(PortalInvitationSecret::matchesCandidate($bad,$token->digest()));
        }

        foreach([
            strtoupper($token->digest()),
            str_repeat('0',63),
            str_repeat('z',64),
            'hash-from-crm',
        ] as $digest) {
            self::assertFalse(PortalInvitationSecret::matchesCandidate($valid,$digest));
        }
    }

    public function test_debug_output_redacts_secrets_and_serialization_is_blocked(): void
    {
        $token=PortalInvitationSecret::mint();
        $digest=$token->digest();
        $debug=print_r($token,true);
        self::assertStringContainsString('[redacted]',$debug);
        self::assertStringNotContainsString($digest,$debug);

        $this->expectException(LogicException::class);
        serialize($token);
    }

    public function test_object_inspection_and_export_do_not_emit_any_token_or_digest(): void
    {
        $token=PortalInvitationSecret::mint();
        $digest=$token->digest();
        $beforeExport=var_export($token,true);
        $beforeDebug=print_r($token,true);

        $bearer=$token->takeDeliverySecretOnce();
        self::assertIsString($bearer);
        self::assertSame($digest,hash('sha256',$bearer));
        self::assertNull($token->takeDeliverySecretOnce());

        foreach ([
            $beforeExport, $beforeDebug,
            var_export($token,true), print_r($token,true),
            json_encode($token,JSON_THROW_ON_ERROR),
        ] as $diagnostic) {
            self::assertStringNotContainsString($bearer,$diagnostic);
            self::assertStringNotContainsString($digest,$diagnostic);
        }

        self::assertSame('{}',json_encode($token,JSON_THROW_ON_ERROR));
        $instanceProperties=array_filter(
            (new \ReflectionObject($token))->getProperties(),
            static fn(\ReflectionProperty $property):bool=>!$property->isStatic()
        );
        self::assertCount(0,$instanceProperties);
        self::assertSame($digest,$token->digest());
    }

    public function test_export_rehydration_and_implicit_string_conversion_are_blocked(): void
    {
        $token=PortalInvitationSecret::mint();
        try {
            PortalInvitationSecret::__set_state([]);
            self::fail('var_export state restoration must be denied');
        } catch (LogicException) {
            self::assertTrue(true);
        }

        $this->expectException(LogicException::class);
        (string)$token;
    }

}
