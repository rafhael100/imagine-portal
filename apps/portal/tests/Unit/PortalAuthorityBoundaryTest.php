<?php

namespace Tests\Unit;

use App\Domain\Authorization\Permission;
use App\Domain\Identity\PortalUserId;
use App\Domain\Organizations\MembershipStatus;
use App\Domain\Organizations\OrganizationId;
use App\Domain\Organizations\OrganizationMembership;
use App\Domain\Organizations\OrganizationRole;
use App\Domain\PortalAccess\Authority\DenyingPortalAuthorities;
use App\Domain\PortalAccess\Authority\PortalAuthorityBoundary;
use App\Domain\PortalAccess\Authority\PortalMembershipAuthority;
use App\Domain\PortalAccess\Authority\PortalResourceAuthority;
use App\Domain\PortalAccess\Authority\PortalSessionAuthority;
use App\Domain\PortalAccess\Authority\PortalSessionSnapshot;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * F1 synthetic composition checks only.
 *
 * These fake authorities DO NOT authenticate a real Portal account, verify
 * an issuer/MFA, query a store, exercise locks or PostgreSQL RLS. Even the
 * synthetic-positive verdict has canAuthorizeHttp() === false.
 */
final class PortalAuthorityBoundaryTest extends TestCase
{
    private function snapshot(
        string $actor = 'portal-user-a',
        bool $active = true,
        bool $mfa = true,
        int $accountRevision = 4,
        int $sessionAccountRevision = 4,
        int $expiresAt = 2000,
        string $sessionOrg = 'org-a',
        int $sessionMembershipRevision = 1,
    ): PortalSessionSnapshot {
        return new PortalSessionSnapshot(
            new PortalUserId($actor),
            $active,
            $mfa,
            1000,
            $expiresAt,
            $accountRevision,
            $sessionAccountRevision,
            new OrganizationId($sessionOrg),
            $sessionMembershipRevision,
        );
    }

    private function membership(
        string $actor = 'portal-user-a',
        string $organization = 'org-a',
        MembershipStatus $status = MembershipStatus::Active,
        array $permissions = [Permission::ViewServices],
        int $revision = 1,
    ): OrganizationMembership {
        return new OrganizationMembership(
            new PortalUserId($actor),
            new OrganizationId($organization),
            OrganizationRole::Member,
            $status,
            $permissions,
            $revision,
        );
    }

    private function syntheticBoundary(
        ?PortalSessionSnapshot $snapshot,
        ?OrganizationMembership $membership,
        ?OrganizationId $owner,
    ): PortalAuthorityBoundary {
        // Test fakes reside ONLY in tests, not in the production container.
        $sessionSource = new class($snapshot) implements PortalSessionAuthority {
            public function __construct(private ?PortalSessionSnapshot $snapshot)
            {
            }

            public function find(string $opaqueReference): ?PortalSessionSnapshot
            {
                return $opaqueReference === 'test-portal-session' ? $this->snapshot : null;
            }
        };

        $membershipSource = new class($membership) implements PortalMembershipAuthority {
            public function __construct(private ?OrganizationMembership $membership)
            {
            }

            public function findFor(PortalUserId $actor, OrganizationId $organization): ?OrganizationMembership
            {
                return $this->membership;
            }
        };

        $resourceSource = new class($owner) implements PortalResourceAuthority {
            public function __construct(private ?OrganizationId $owner)
            {
            }

            public function ownerOf(OrganizationId $selectedOrganization, string $opaqueResourceReference): ?OrganizationId
            {
                return $opaqueResourceReference === 'synthetic-resource' ? $this->owner : null;
            }
        };

        return new PortalAuthorityBoundary($sessionSource, $membershipSource, $resourceSource);
    }

    private function inspect(
        PortalAuthorityBoundary $boundary,
        ?string $handle = 'test-portal-session',
        ?string $organization = 'org-a',
        ?string $resource = 'synthetic-resource',
        string $permission = 'services.view',
        int $now = 1500,
    ): bool {
        $verdict = $boundary->inspect(
            $handle,
            $organization === null ? null : new OrganizationId($organization),
            $resource,
            $permission,
            $now,
        );

        self::assertFalse($verdict->canAuthorizeHttp());

        return $verdict->syntheticChecksPass;
    }

    public function test_default_providers_deny_everything_without_official_authority(): void
    {
        $providers = new DenyingPortalAuthorities();
        $boundary = new PortalAuthorityBoundary($providers, $providers, $providers);

        self::assertFalse($this->inspect($boundary));
        self::assertFalse($this->inspect($boundary, null));
        self::assertFalse($this->inspect($boundary, 'crm-cookie-with-same-user-id'));
    }

    public function test_matching_test_fakes_create_only_a_synthetic_candidate_not_http_permission(): void
    {
        $boundary = $this->syntheticBoundary(
            $this->snapshot(),
            $this->membership(),
            new OrganizationId('org-a'),
        );

        self::assertTrue($this->inspect($boundary));
        self::assertFalse($this->inspect($boundary, 'crm-cookie-with-same-user-id'));
    }

    public function test_unauthenticated_missing_and_invalid_inputs_fail_closed(): void
    {
        $boundary = $this->syntheticBoundary($this->snapshot(), $this->membership(), new OrganizationId('org-a'));

        self::assertFalse($this->inspect($boundary, null));
        self::assertFalse($this->inspect($boundary, ''));
        self::assertFalse($this->inspect($boundary, 'test-portal-session', null));
        self::assertFalse($this->inspect($boundary, 'test-portal-session', 'org-a', null));
        self::assertFalse($this->inspect($boundary, 'test-portal-session', 'org-a', ''));
        self::assertFalse($this->inspect($boundary, 'test-portal-session', 'org-a', 'synthetic-resource', 'services.view', -1));
        self::assertFalse($this->inspect($boundary, str_repeat('x', 513)));
    }

    public function test_session_revoked_mfa_missing_expired_and_revision_stale_are_denied(): void
    {
        foreach ([
            $this->snapshot(active: false),
            $this->snapshot(mfa: false),
            $this->snapshot(expiresAt: 1499),
            $this->snapshot(sessionAccountRevision: 3),
        ] as $snapshot) {
            $boundary = $this->syntheticBoundary($snapshot, $this->membership(), new OrganizationId('org-a'));

            self::assertFalse($this->inspect($boundary));
        }
    }

    public function test_cross_tenant_owner_foreign_membership_and_wrong_actor_are_denied(): void
    {
        $cases = [
            [$this->membership('other-actor'), new OrganizationId('org-a')],
            [$this->membership('portal-user-a', 'org-b'), new OrganizationId('org-a')],
            [$this->membership(), new OrganizationId('org-b')],
            [null, new OrganizationId('org-a')],
            [$this->membership(status: MembershipStatus::Revoked), new OrganizationId('org-a')],
        ];

        foreach ($cases as [$membership, $owner]) {
            $boundary = $this->syntheticBoundary($this->snapshot(), $membership, $owner);
            self::assertFalse($this->inspect($boundary));
        }
    }

    public function test_session_tenant_binding_prevents_cross_org_session_reuse_for_multi_company_actor(): void
    {
        // A valid actor may have an explicit membership and resource in two
        // companies, yet a session from org A must never authorize org B.
        $aSession = $this->snapshot(sessionOrg: 'org-a');
        $orgBMembership = $this->membership(organization: 'org-b');
        $orgBBoundary = $this->syntheticBoundary(
            $aSession,
            $orgBMembership,
            new OrganizationId('org-b'),
        );
        self::assertFalse($this->inspect($orgBBoundary, organization: 'org-b'));

        $orgBSession = $this->snapshot(sessionOrg: 'org-b');
        $orgBBoundary = $this->syntheticBoundary(
            $orgBSession,
            $orgBMembership,
            new OrganizationId('org-b'),
        );
        // Still a synthetic verdict, NEVER HTTP authorization.
        self::assertTrue($this->inspect($orgBBoundary, organization: 'org-b'));

        self::assertFalse($this->inspect(
            $this->syntheticBoundary(
                $orgBSession,
                $this->membership(organization: 'org-a'),
                new OrganizationId('org-a'),
            ),
            organization: 'org-a',
        ));
    }

    public function test_stale_session_membership_revision_is_denied_even_when_membership_is_active(): void
    {
        // Grant/role change increments the authoritative member revision.
        // A session carrying the previous revision must be invalidated.
        $member = $this->membership(revision: 2);
        $owner = new OrganizationId('org-a');
        $stale = $this->syntheticBoundary(
            $this->snapshot(sessionMembershipRevision: 1),
            $member,
            $owner,
        );
        self::assertFalse($this->inspect($stale));

        $current = $this->syntheticBoundary(
            $this->snapshot(sessionMembershipRevision: 2),
            $member,
            $owner,
        );
        // The positive signal is synthetic only, not a real grant.
        self::assertTrue($this->inspect($current));

        // A current membership snapshot cannot be downgraded to a
        // session revision without reissuing a session.
        self::assertFalse($this->inspect($this->syntheticBoundary(
            $this->snapshot(sessionMembershipRevision: 2),
            $this->membership(revision: 1),
            $owner,
        )));
    }

    public function test_invalid_membership_revision_is_not_a_valid_authority_snapshot(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->membership(revision: 0);
    }

    public function test_invalid_session_membership_revision_is_not_a_valid_session_snapshot(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->snapshot(sessionMembershipRevision: 0);
    }

    public function test_header_grants_or_wildcard_cannot_replace_authoritative_permission(): void
    {
        $boundary = $this->syntheticBoundary(
            $this->snapshot(),
            $this->membership(permissions: []),
            new OrganizationId('org-a'),
        );

        foreach (['services.view', 'services.manage', '*', '', 'fake-header-grant'] as $permission) {
            self::assertFalse($this->inspect($boundary, permission: $permission));
        }
    }

    public function test_provider_exception_denies_without_exposing_tenant_information(): void
    {
        $broken = new class implements PortalSessionAuthority {
            public function find(string $opaqueReference): ?PortalSessionSnapshot
            {
                throw new RuntimeException('sensitive: org-b / session-secret');
            }
        };
        $denied = new DenyingPortalAuthorities();
        $boundary = new PortalAuthorityBoundary($broken, $denied, $denied);

        $verdict = $boundary->inspect('test-portal-session', new OrganizationId('org-a'), 'synthetic-resource', 'services.view', 1500);

        self::assertFalse($verdict->syntheticChecksPass);
        self::assertFalse($verdict->canAuthorizeHttp());
    }
}
