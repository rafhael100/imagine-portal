<?php

declare(strict_types=1);

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
use App\Domain\SeoReview\GoogleSearchConsoleConnectionPreflight;
use App\Domain\SeoReview\GoogleSearchConsoleConnectionReview;
use App\Domain\SeoReview\GoogleSearchConsolePropertyIdentifier;
use PHPUnit\Framework\TestCase;

/**
 * F131 test doubles represent an intentionally UNTRUSTED offline preview.
 * Successful tests MUST NOT be interpreted as trusted IAM or provider OAuth.
 */
final class GoogleSearchConsoleConnectionPreflightTest extends TestCase
{
    public const DOMAIN = 'sc-domain:imaginecomunicacao.com.br';
    private const PREFIX = 'https://www.imaginecomunicacao.com.br/';

    private function session(
        bool $mfa = true,
        bool $active = true,
        int $revision = 4,
        string $actor = 'actor-a',
        int $expiry = 2000,
        string $sessionOrg = 'org-a',
    ): PortalSessionSnapshot {
        return new PortalSessionSnapshot(
            new PortalUserId($actor),
            $active,
            $mfa,
            1000,
            $expiry,
            4,
            $revision,
            new OrganizationId($sessionOrg),
        );
    }

    /** @param list<Permission> $permissions */
    private function membership(
        array $permissions = [Permission::ConnectGoogleSearchConsole],
        MembershipStatus $status = MembershipStatus::Active,
        string $organization = 'org-a',
        string $actor = 'actor-a',
    ): OrganizationMembership {
        return new OrganizationMembership(
            new PortalUserId($actor),
            new OrganizationId($organization),
            OrganizationRole::Owner,
            $status,
            $permissions,
        );
    }

    private function preflight(
        ?PortalSessionSnapshot $session,
        ?OrganizationMembership $membership,
        ?OrganizationId $resourceOwner = null,
    ): GoogleSearchConsoleConnectionPreflight {
        $sessionSource = new class($session) implements PortalSessionAuthority {
            public function __construct(private ?PortalSessionSnapshot $snapshot)
            {
            }

            public function find(string $opaqueReference): ?PortalSessionSnapshot
            {
                return $opaqueReference === 'portal-only-session'
                    ? $this->snapshot : null;
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

        $resourceSource = new class($resourceOwner) implements PortalResourceAuthority {
            public function __construct(private ?OrganizationId $owner)
            {
            }

            public function ownerOf(
                OrganizationId $selectedOrganization,
                string $opaqueResourceReference,
            ): ?OrganizationId {
                $expected = 'gsc-property-sha256:'
                    .hash('sha256', GoogleSearchConsoleConnectionPreflightTest::DOMAIN);

                return $opaqueResourceReference === $expected
                    ? $this->owner : null;
            }
        };

        return new GoogleSearchConsoleConnectionPreflight(
            new PortalAuthorityBoundary(
                $sessionSource,
                $membershipSource,
                $resourceSource,
            ),
        );
    }

    private function verdict(
        GoogleSearchConsoleConnectionPreflight $service,
        mixed $property = self::DOMAIN,
        ?string $session = 'portal-only-session',
        ?string $organization = 'org-a',
        int $now = 1500,
    ): GoogleSearchConsoleConnectionReview {
        $result = $service->inspect(
            $session,
            $organization === null ? null : new OrganizationId($organization),
            $property,
            $now,
        );

        self::assertFalse($result->canStartOAuth());
        self::assertFalse($result->canReadGoogle());
        self::assertFalse($result->canStoreTokens());

        return $result;
    }

    public function test_application_default_authorities_deny_even_valid_site_and_permission(): void
    {
        $deny = new DenyingPortalAuthorities();
        $preflight = new GoogleSearchConsoleConnectionPreflight(
            new PortalAuthorityBoundary($deny, $deny, $deny),
        );
        self::assertFalse($this->verdict($preflight)->syntheticEligibilityPass);
    }

    public function test_only_explicit_matching_synthetic_grant_is_a_non_operational_candidate(): void
    {
        $service = $this->preflight(
            $this->session(),
            $this->membership(),
            new OrganizationId('org-a'),
        );
        self::assertTrue($this->verdict($service)->syntheticEligibilityPass);
        self::assertSame(
            hash('sha256', self::DOMAIN),
            GoogleSearchConsolePropertyIdentifier::fingerprint(self::DOMAIN),
        );

        // A positive PURE review is not a verified Portal identity,
        // a Search Console permission, or authorization to leave the app.
        self::assertFalse($this->verdict($service, self::PREFIX)->syntheticEligibilityPass);
        self::assertFalse($this->verdict($service, 'sc-domain:www.imaginecomunicacao.com.br')->syntheticEligibilityPass);
        self::assertFalse($this->verdict($service, 'https://imaginecomunicacao.com.br/')->syntheticEligibilityPass);
    }

    public function test_missing_grant_wrong_tenant_actor_and_membership_status_deny(): void
    {
        $cases = [
            $this->preflight($this->session(), $this->membership([]), new OrganizationId('org-a')),
            $this->preflight($this->session(), $this->membership(
                [Permission::ViewServices, Permission::ManageMembers],
            ), new OrganizationId('org-a')),
            $this->preflight($this->session(), $this->membership(
                organization:'org-b',
            ), new OrganizationId('org-a')),
            $this->preflight($this->session(), $this->membership(
                actor:'actor-b',
            ), new OrganizationId('org-a')),
            $this->preflight($this->session(), $this->membership(
                status:MembershipStatus::Invited,
            ), new OrganizationId('org-a')),
            $this->preflight($this->session(), $this->membership(
                status:MembershipStatus::Revoked,
            ), new OrganizationId('org-a')),
            $this->preflight($this->session(), $this->membership(
                status:MembershipStatus::Suspended,
            ), new OrganizationId('org-a')),
            $this->preflight($this->session(), $this->membership(), new OrganizationId('org-b')),
            $this->preflight($this->session(), null, new OrganizationId('org-a')),
        ];

        foreach ($cases as $case) {
            self::assertFalse($this->verdict($case)->syntheticEligibilityPass);
        }
    }

    public function test_expired_wrong_session_unverified_mfa_and_revoked_session_deny(): void
    {
        foreach ([
            $this->session(mfa:false),
            $this->session(active:false),
            $this->session(revision:3),
            $this->session(expiry:1400),
            $this->session(sessionOrg:'org-b'),
        ] as $snapshot) {
            $service = $this->preflight(
                $snapshot, $this->membership(), new OrganizationId('org-a'),
            );
            self::assertFalse($this->verdict($service)->syntheticEligibilityPass);
        }

        $service = $this->preflight(
            $this->session(), $this->membership(), new OrganizationId('org-a'),
        );
        self::assertFalse($this->verdict($service, session:'crm-session-cookie')->syntheticEligibilityPass);
        self::assertFalse($this->verdict($service, session:null)->syntheticEligibilityPass);
        self::assertFalse($this->verdict($service, organization:null)->syntheticEligibilityPass);
        self::assertFalse($this->verdict($service, organization:'org-b')->syntheticEligibilityPass);
        self::assertFalse($this->verdict($service, now:0)->syntheticEligibilityPass);
    }

    public function test_untrusted_or_ambiguous_property_identifiers_fail_closed(): void
    {
        $service = $this->preflight(
            $this->session(), $this->membership(), new OrganizationId('org-a'),
        );

        foreach ([
            null, false, [], '', 'sc-domain:EXAMPLE.com',
            'sc-domain:imaginecomunicacao.com.br/',
            'https://www.imaginecomunicacao.com.br/?state=forged',
            'https://www.imaginecomunicacao.com.br:443/',
            'https://www.imaginecomunicacao.com.br/../secret',
        ] as $invalid) {
            self::assertFalse($this->verdict($service, property:$invalid)->syntheticEligibilityPass);
        }
    }
}
