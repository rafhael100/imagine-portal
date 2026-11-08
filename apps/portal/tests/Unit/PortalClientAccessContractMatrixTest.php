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
use App\Presentation\PviewSection;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * CLIENT-ARCH-01: synthetic contract regression only.
 * This test can NEVER establish trusted identity, real MFA, revocation
 * concurrency, SQL RLS or HTTP permission. Fakes MUST stay in tests.
 */
final class PortalClientAccessContractMatrixTest extends TestCase
{
    public function test_eight_preview_sections_do_not_create_eight_access_entitlements(): void
    {
        $deny = new DenyingPortalAuthorities();
        $boundary = new PortalAuthorityBoundary($deny, $deny, $deny);

        self::assertCount(8, PviewSection::cases());

        foreach (PviewSection::cases() as $section) {
            $candidate = $boundary->inspect(
                'synthetic-reference',
                new OrganizationId('org-a'),
                'synthetic-resource',
                'services.view',
                1500,
            );

            self::assertFalse($candidate->syntheticChecksPass, $section->value);
            self::assertFalse($candidate->canAuthorizeHttp(), $section->value);
        }
    }

    public function test_a_synthetic_candidate_still_denies_http_and_revocation_is_rechecked_in_memory(): void
    {
        $a = new PortalUserId('portal-user-a');
        $orgA = new OrganizationId('org-a');
        $orgB = new OrganizationId('org-b');

        $source = new class($a, $orgA) implements PortalSessionAuthority, PortalMembershipAuthority, PortalResourceAuthority {
            public bool $sessionActive = true;
            public bool $membershipActive = true;
            public bool $wrongResourceOwner = false;
            public bool $fail = false;

            public function __construct(
                private readonly PortalUserId $actor,
                private readonly OrganizationId $orgA,
            ) {
            }

            public function find(string $opaqueReference): ?PortalSessionSnapshot
            {
                if ($this->fail) {
                    throw new RuntimeException('synthetic private backend detail');
                }

                if ($opaqueReference !== 'portal-session-synthetic-a') {
                    return null;
                }

                return new PortalSessionSnapshot(
                    $this->actor,
                    $this->sessionActive,
                    true,
                    1000,
                    2000,
                    4,
                    4,
                );
            }

            public function findFor(PortalUserId $actor, OrganizationId $organization): ?OrganizationMembership
            {
                return new OrganizationMembership(
                    $this->actor,
                    $this->orgA,
                    OrganizationRole::Member,
                    $this->membershipActive ? MembershipStatus::Active : MembershipStatus::Revoked,
                    [Permission::ViewServices],
                );
            }

            public function ownerOf(OrganizationId $selectedOrganization, string $opaqueResourceReference): ?OrganizationId
            {
                if ($opaqueResourceReference !== 'resource-a') {
                    return null;
                }

                return $this->wrongResourceOwner ? new OrganizationId('org-b') : $this->orgA;
            }
        };

        $boundary = new PortalAuthorityBoundary($source, $source, $source);
        $evaluate = static function (
            PortalAuthorityBoundary $boundary,
            string $handle = 'portal-session-synthetic-a',
            string $org = 'org-a',
            string $resource = 'resource-a',
            string $permission = 'services.view',
        ): array {
            $result = $boundary->inspect(
                $handle,
                new OrganizationId($org),
                $resource,
                $permission,
                1500,
            );

            return [$result->syntheticChecksPass, $result->canAuthorizeHttp()];
        };

        self::assertSame([true, false], $evaluate($boundary), 'Positive synthetic checks must NEVER grant HTTP.');
        self::assertSame([false, false], $evaluate($boundary, org: 'org-b'), 'Organization selection is NOT membership.');
        self::assertSame([false, false], $evaluate($boundary, handle: 'crm-session-same-email'), 'CRM handle cannot sign into Portal.');
        self::assertSame([false, false], $evaluate($boundary, permission: '*'), 'Wildcard never becomes a known grant.');
        self::assertSame([false, false], $evaluate($boundary, permission: 'billing.view'), 'Unassigned permission is denied.');
        self::assertSame([false, false], $evaluate($boundary, resource: 'resource-b'), 'Unknown resource is denied.');

        $source->wrongResourceOwner = true;
        self::assertSame([false, false], $evaluate($boundary), 'Cross-tenant owner is denied.');
        $source->wrongResourceOwner = false;

        $source->membershipActive = false;
        self::assertSame([false, false], $evaluate($boundary), 'Revoked synthetic membership is denied.');
        $source->membershipActive = true;

        $source->sessionActive = false;
        self::assertSame([false, false], $evaluate($boundary), 'Revoked synthetic session is denied.');
        $source->sessionActive = true;

        $source->fail = true;
        self::assertSame([false, false], $evaluate($boundary), 'Backend error must fail closed.');
    }
}
