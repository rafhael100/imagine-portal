<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Identity\Security\PortalEmailProof;
use PHPUnit\Framework\TestCase;

final class PortalEmailProofTest extends TestCase
{
    private const ID = '11111111-1111-4111-8111-111111111111';
    private const ORG = '22222222-2222-4222-8222-222222222222';
    private const SECOND_ID = '33333333-3333-4333-8333-333333333333';
    private const SECOND_ORG = '44444444-4444-4444-8444-444444444444';
    private const EMAIL = 'operator@example.invalid';

    public function test_opaque_token_is_high_entropy_scoped_and_never_a_login_grant(): void
    {
        $key = random_bytes(32);
        $first = PortalEmailProof::issue(self::ID, self::ORG, self::EMAIL, 1, $key);
        $second = PortalEmailProof::issue(self::ID, self::ORG, self::EMAIL, 1, $key);
        self::assertIsArray($first);
        self::assertIsArray($second);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $first['token']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $first['digest']);
        self::assertNotSame($first['token'], $second['token']);
        self::assertNotSame($first['digest'], $second['digest']);
        self::assertNotSame(hash('sha256', $first['token']), $first['digest']);
        self::assertTrue(PortalEmailProof::matches(
            $first['token'], $first['digest'], self::ID, self::ORG, self::EMAIL, 1, $key,
        ));
        self::assertFalse(PortalEmailProof::grantsAccess());
        self::assertFalse(method_exists(PortalEmailProof::class, 'sendEmail'));
        self::assertFalse(method_exists(PortalEmailProof::class, 'authenticate'));
        self::assertFalse(method_exists(PortalEmailProof::class, 'issueSession'));
    }

    public function test_cross_tenant_actor_email_revision_and_server_key_replay_are_rejected(): void
    {
        $key = random_bytes(32);
        $pair = PortalEmailProof::issue(self::ID, self::ORG, self::EMAIL, 9, $key);
        self::assertIsArray($pair);
        foreach ([
            [self::SECOND_ID, self::ORG, self::EMAIL, 9, $key],
            [self::ID, self::SECOND_ORG, self::EMAIL, 9, $key],
            [self::ID, self::ORG, 'other@example.invalid', 9, $key],
            [self::ID, self::ORG, self::EMAIL, 10, $key],
            [self::ID, self::ORG, self::EMAIL, 8, $key],
            [self::ID, self::ORG, self::EMAIL, 9, random_bytes(32)],
        ] as [$identity, $org, $email, $rev, $secret]) {
            self::assertFalse(PortalEmailProof::matches(
                $pair['token'], $pair['digest'],
                $identity, $org, $email, $rev, $secret,
            ));
        }
    }

    public function test_malformed_tokens_scopes_keys_and_digests_fail_closed(): void
    {
        $key = random_bytes(32);
        $pair = PortalEmailProof::issue(self::ID, self::ORG, self::EMAIL, 1, $key);
        self::assertIsArray($pair);
        foreach ([
            '',
            'plain',
            strtoupper($pair['token']),
            $pair['token'] . '0',
            substr($pair['token'], 0, -1),
            str_repeat('f', 64),
        ] as $token) {
            self::assertFalse(PortalEmailProof::matches(
                $token, $pair['digest'], self::ID, self::ORG, self::EMAIL, 1, $key,
            ));
        }
        foreach ([
            '', strtoupper($pair['digest']), str_repeat('z', 64),
            $pair['token'],
        ] as $digest) {
            self::assertFalse(PortalEmailProof::matches(
                $pair['token'], $digest, self::ID, self::ORG, self::EMAIL, 1, $key,
            ));
        }
        foreach ([
            [self::ID, self::ID, self::EMAIL, 1],
            ['invalid-uuid', self::ORG, self::EMAIL, 1],
            [self::ID, self::ORG, 'OPERATOR@example.invalid', 1],
            [self::ID, self::ORG, 'operator@example.invalid' . "\0", 1],
            [self::ID, self::ORG, self::EMAIL, 0],
            [self::ID, self::ORG, self::EMAIL, -1],
        ] as [$identity, $org, $email, $revision]) {
            self::assertNull(PortalEmailProof::issue($identity, $org, $email, $revision, $key));
            self::assertFalse(PortalEmailProof::matches(
                $pair['token'], $pair['digest'], $identity, $org, $email, $revision, $key,
            ));
        }
        self::assertNull(PortalEmailProof::issue(
            self::ID, self::ORG, self::EMAIL, 1, 'not-32-byte-key',
        ));
        self::assertFalse(PortalEmailProof::matches(
            $pair['token'], $pair['digest'], self::ID, self::ORG, self::EMAIL, 1, 'wrong',
        ));
    }

    public function test_no_plaintext_token_is_embedded_in_verifier_or_diagnostics(): void
    {
        $pair = PortalEmailProof::issue(self::ID, self::ORG, self::EMAIL, 1, random_bytes(32));
        self::assertIsArray($pair);
        self::assertStringNotContainsString($pair['token'], $pair['digest']);
        self::assertStringNotContainsString(self::EMAIL, $pair['digest']);
    }
}
