<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Identity\Invitations\PortalInvitationActorEvidence;
use App\Domain\Identity\Invitations\PortalInvitationIdentitySource;
use App\Domain\Identity\Invitations\PortalInvitationPreflight;
use App\Domain\Identity\Invitations\PortalInvitationPreflightVerdict;
use App\Domain\Identity\Invitations\PortalInvitationSecret;
use App\Domain\Identity\PortalUserId;
use App\Domain\Organizations\OrganizationId;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PortalInvitationPreflightTest extends TestCase
{
    private const NOW = 1_700_000_200;

    /** @param array<string,mixed> $overrides */
    private static function evidence(array $overrides = []): PortalInvitationActorEvidence
    {
        $data = array_replace([
            'actor' => new PortalUserId('portal-actor-1'),
            'organization' => new OrganizationId('org-a'),
            'sessionActive' => true,
            'emailVerified' => true,
            'mfaVerified' => true,
            'sessionIssuedAt' => 1_700_000_000,
            'sessionExpiresAt' => 1_700_003_600,
            'emailVerifiedAt' => 1_699_999_000,
            'mfaVerifiedAt' => 1_700_000_100,
            'identityRevision' => 4,
            'sessionIdentityRevision' => 4,
            'membershipRevision' => 7,
            'sessionMembershipRevision' => 7,
            'organizationRevision' => 3,
            'sessionOrganizationRevision' => 3,
        ], $overrides);

        return new PortalInvitationActorEvidence(...$data);
    }

    private static function source(
        ?PortalInvitationActorEvidence $evidence,
        bool $throw = false,
    ): PortalInvitationIdentitySource {
        return new class($evidence, $throw) implements PortalInvitationIdentitySource
        {
            public int $calls = 0;

            public function __construct(
                private ?PortalInvitationActorEvidence $evidence,
                private bool $throw,
            ) {
            }

            public function findFor(
                string $opaquePortalSessionReference,
                OrganizationId $selectedOrganization,
            ): ?PortalInvitationActorEvidence {
                $this->calls++;
                if ($this->throw) {
                    throw new RuntimeException('sensitive simulated provider error');
                }
                return $this->evidence;
            }
        };
    }

    private static function bearer(): string
    {
        $value = PortalInvitationSecret::mint()->takeDeliverySecretOnce();
        self::assertIsString($value);

        return $value;
    }

    public function test_valid_synthetic_evidence_is_never_an_operational_grant(): void
    {
        $gate = new PortalInvitationPreflight(self::source(self::evidence()));
        $verdict = $gate->inspect(
            'synthetic-portal-session',
            self::bearer(),
            new OrganizationId('org-a'),
            self::NOW,
        );

        self::assertInstanceOf(PortalInvitationPreflightVerdict::class, $verdict);
        self::assertTrue($verdict->syntheticInputsPlausible);
        self::assertFalse($verdict->canConsumeInvitation());
        self::assertFalse($verdict->canIssueSession());
        self::assertFalse($verdict->canAuthorizeHttp());
        self::assertSame(
            '{"syntheticInputsPlausible":true}',
            json_encode($verdict, JSON_THROW_ON_ERROR),
        );
    }

    public function test_untrusted_session_evidence_failure_or_exception_fails_closed(): void
    {
        foreach ([self::source(null), self::source(self::evidence(), true)] as $source) {
            $verdict = (new PortalInvitationPreflight($source))->inspect(
                'portal-session',
                self::bearer(),
                new OrganizationId('org-a'),
                self::NOW,
            );
            self::assertFalse($verdict->syntheticInputsPlausible);
            self::assertFalse($verdict->canAuthorizeHttp());
            self::assertSame(1, $source->calls);
        }
    }

    public function test_input_validation_blocks_before_consulting_identity_authority(): void
    {
        $source = self::source(self::evidence());
        $gate = new PortalInvitationPreflight($source);
        $token = self::bearer();
        $org = new OrganizationId('org-a');

        foreach ([
            [null, $token, $org, self::NOW],
            ['', $token, $org, self::NOW],
            [str_repeat('s', 257), $token, $org, self::NOW],
            ['valid-looking-session', null, $org, self::NOW],
            ['valid-looking-session', '', $org, self::NOW],
            ['valid-looking-session', $token.'=', $org, self::NOW],
            ['valid-looking-session', 'CRM-session-not-an-invite', $org, self::NOW],
            ['valid-looking-session', $token, null, self::NOW],
            ['valid-looking-session', $token, $org, -1],
        ] as [$session, $invite, $organization, $now]) {
            $verdict = $gate->inspect($session, $invite, $organization, $now);
            self::assertFalse($verdict->syntheticInputsPlausible);
            self::assertFalse($verdict->canConsumeInvitation());
        }

        self::assertSame(0, $source->calls);
    }

    public function test_stale_mfa_revisions_other_tenant_and_invalid_sessions_are_rejected(): void
    {
        $mutations = [
            ['sessionActive' => false],
            ['emailVerified' => false],
            ['mfaVerified' => false],
            ['organization' => new OrganizationId('org-b')],
            ['sessionIssuedAt' => self::NOW + 1],
            ['sessionExpiresAt' => self::NOW],
            ['emailVerifiedAt' => 0],
            ['emailVerifiedAt' => self::NOW + 1],
            ['mfaVerifiedAt' => 0],
            ['mfaVerifiedAt' => 1_700_000_000 - 1],
            ['mfaVerifiedAt' => self::NOW + 1],
            ['mfaVerifiedAt' => self::NOW - 301],
            ['identityRevision' => 5],
            ['sessionIdentityRevision' => 5],
            ['membershipRevision' => 8],
            ['sessionMembershipRevision' => 8],
            ['organizationRevision' => 4],
            ['sessionOrganizationRevision' => 4],
        ];

        foreach ($mutations as $change) {
            $gate = new PortalInvitationPreflight(self::source(self::evidence($change)));
            $result = $gate->inspect(
                'portal-session',
                self::bearer(),
                new OrganizationId('org-a'),
                self::NOW,
            );
            self::assertFalse(
                $result->syntheticInputsPlausible,
                'Expected synthetic preflight to reject '.implode(',', array_keys($change)),
            );
            self::assertFalse($result->canAuthorizeHttp());
        }
    }

    public function test_recent_mfa_on_boundary_is_accepted_as_non_operational_candidate(): void
    {
        $gate = new PortalInvitationPreflight(self::source(
            self::evidence(['mfaVerifiedAt' => self::NOW - 300]),
        ));
        $result = $gate->inspect(
            'portal-session',
            self::bearer(),
            new OrganizationId('org-a'),
            self::NOW,
        );
        self::assertTrue($result->syntheticInputsPlausible);
        self::assertFalse($result->canAuthorizeHttp());
    }
}
