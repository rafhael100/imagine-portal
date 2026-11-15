<?php
namespace Tests\Unit;

use App\Domain\Authorization\Permission;
use App\Domain\Identity\PortalUserId;
use App\Domain\Organizations\MembershipStatus;
use App\Domain\Organizations\OrganizationId;
use App\Domain\Organizations\OrganizationMembership;
use App\Domain\Organizations\OrganizationRole;
use App\Domain\PortalAccess\DemoAccessBlocker;
use App\Domain\PortalAccess\DemoNavigationPolicy;
use App\Domain\PortalAccess\DemoPortalSession;
use App\Domain\PortalAccess\DemoPortalSessionState;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class DemoNavigationPolicyTest extends TestCase
{
    private const NOW = 1793970000; // A fixed synthetic clock.

    private function session(
        DemoPortalSessionState $state = DemoPortalSessionState::Active,
        ?int $mfaAt = self::NOW - 20,
        string $org = 'org-demo-a',
        string $actor = 'actor-demo-a',
        int $issuedAt = self::NOW - 500,
        int $expiresAt = self::NOW + 500,
    ): DemoPortalSession {
        return new DemoPortalSession('demo_session_example', new PortalUserId($actor),
            new OrganizationId($org), $state, $issuedAt, $expiresAt, $mfaAt);
    }

    private function member(
        string $org = 'org-demo-a',
        string $actor = 'actor-demo-a',
        MembershipStatus $status = MembershipStatus::Active,
        array $permissions = [Permission::ViewServices],
    ): OrganizationMembership {
        return new OrganizationMembership(new PortalUserId($actor), new OrganizationId($org),
            OrganizationRole::Member, $status, $permissions);
    }

    private function check(
        ?DemoPortalSession $session,
        ?OrganizationId $selectedOrg = null,
        ?OrganizationId $resourceOrg = null,
        ?OrganizationMembership $membership = null,
        int $now = self::NOW,
    ) {
        return (new DemoNavigationPolicy())->inspect(
            $session, $selectedOrg ?? new OrganizationId('org-demo-a'),
            $resourceOrg ?? new OrganizationId('org-demo-a'), $membership ?? $this->member(), $now,
        );
    }

    public function test_valid_synthetic_context_is_never_real_authorization(): void
    {
        $session = $this->session();
        $result = $this->check($session);
        self::assertTrue($result->wouldShowSyntheticPreview);
        self::assertNull($result->blocker);
        self::assertFalse($result->canAuthorizeHttp());
        self::assertFalse($session->isAuthenticationEvidence());
    }

    public function test_absent_session_is_denied(): void
    {
        self::assertSame(DemoAccessBlocker::Missing, $this->check(null)->blocker);
    }

    public function test_pending_revoked_and_expired_states_are_denied(): void
    {
        foreach ([DemoPortalSessionState::Pending, DemoPortalSessionState::Revoked, DemoPortalSessionState::Expired] as $state) {
            self::assertSame(DemoAccessBlocker::Inactive, $this->check($this->session(state: $state))->blocker);
        }
    }

    public function test_session_ttl_expiration_is_exclusive(): void
    {
        self::assertSame(DemoAccessBlocker::Expired, $this->check($this->session(expiresAt: self::NOW))->blocker);
        self::assertSame(DemoAccessBlocker::Expired, $this->check($this->session(expiresAt: self::NOW - 1))->blocker);
    }

    public function test_no_mfa_or_stale_mfa_is_rejected(): void
    {
        self::assertSame(DemoAccessBlocker::MfaRequired, $this->check($this->session(mfaAt: null))->blocker);
        self::assertSame(DemoAccessBlocker::MfaStale, $this->check($this->session(mfaAt: self::NOW - 601, issuedAt: self::NOW - 700))->blocker);
        self::assertSame(DemoAccessBlocker::MfaStale, $this->check($this->session(mfaAt: self::NOW + 1))->blocker);
        self::assertSame(DemoAccessBlocker::MfaStale, $this->check($this->session(mfaAt: self::NOW - 501))->blocker);
    }

    public function test_invalid_clock_or_future_session_start_is_denied(): void
    {
        self::assertSame(DemoAccessBlocker::TimeInvalid, $this->check($this->session(), now: 0)->blocker);
        self::assertSame(DemoAccessBlocker::TimeInvalid, $this->check($this->session(
            issuedAt: self::NOW + 1, expiresAt: self::NOW + 600,
        ))->blocker);
    }

    public function test_cross_tenant_and_foreign_resource_are_denied(): void
    {
        $orgA = new OrganizationId('org-demo-a');
        $orgB = new OrganizationId('org-demo-b');
        self::assertSame(DemoAccessBlocker::Organization, $this->check($this->session(), selectedOrg: $orgB)->blocker);
        self::assertSame(DemoAccessBlocker::Organization, $this->check($this->session(), resourceOrg: $orgB)->blocker);
        self::assertSame(DemoAccessBlocker::Organization, $this->check($this->session(org: 'org-demo-b'))->blocker);
        self::assertSame(DemoAccessBlocker::Missing, (new DemoNavigationPolicy())->inspect(
            $this->session(), null, $orgA, $this->member(), self::NOW,
        )->blocker);
    }

    public function test_cross_actor_or_foreign_membership_is_denied(): void
    {
        self::assertSame(DemoAccessBlocker::Permission, $this->check($this->session(actor: 'actor-demo-b'))->blocker);
        self::assertSame(DemoAccessBlocker::Permission, $this->check($this->session(), membership: $this->member(org: 'org-demo-b'))->blocker);
        self::assertSame(DemoAccessBlocker::Permission, (new DemoNavigationPolicy())->inspect(
            $this->session(), new OrganizationId('org-demo-a'), new OrganizationId('org-demo-a'), null, self::NOW,
        )->blocker);
    }

    public function test_revoked_suspended_invited_memberships_and_missing_permission_are_denied(): void
    {
        foreach ([MembershipStatus::Revoked, MembershipStatus::Suspended, MembershipStatus::Invited] as $status) {
            self::assertSame(DemoAccessBlocker::Permission, $this->check(
                $this->session(), membership: $this->member(status: $status),
            )->blocker);
        }
        self::assertSame(DemoAccessBlocker::Permission, $this->check(
            $this->session(), membership: $this->member(permissions: [Permission::ViewBilling]),
        )->blocker);
    }

    public function test_untrusted_equal_ids_can_pass_example_but_never_real_login(): void
    {
        $session = $this->session(actor: 'same-text-as-crm');
        $membership = $this->member(actor: 'same-text-as-crm');
        $result = $this->check($session, membership: $membership);
        self::assertTrue($result->wouldShowSyntheticPreview);
        self::assertFalse($result->canAuthorizeHttp());
    }

    public function test_non_demo_session_label_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new DemoPortalSession('real-session-token', new PortalUserId('actor'), new OrganizationId('org'),
            DemoPortalSessionState::Active, self::NOW - 20, self::NOW + 20, self::NOW - 10);
    }

    public function test_none_of_the_synthetic_states_implies_authenticated_login(): void
    {
        self::assertNull(DemoPortalSessionState::tryFrom('portal_authenticated'));
        self::assertFalse($this->check(null)->canAuthorizeHttp());
        self::assertFalse($this->check($this->session())->canAuthorizeHttp());
    }
}
