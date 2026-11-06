<?php

namespace Tests\Unit;

use App\Domain\Authorization\AuthorizationContext;
use App\Domain\Authorization\OrganizationAuthorizationService;
use App\Domain\Authorization\Permission;
use App\Domain\Identity\PortalUserId;
use App\Domain\Organizations\MembershipStatus;
use App\Domain\Organizations\OrganizationId;
use App\Domain\Organizations\OrganizationMembership;
use App\Domain\Organizations\OrganizationRole;
use PHPUnit\Framework\TestCase;

/**
 * Synthetic input matrix, NOT a test of authentication or membership provenance.
 * No real organization, session, database, token or CRM system is accessed.
 */
final class OrganizationAuthorizationAdversarialMatrixTest extends TestCase
{
    public function test_cross_organization_and_permission_matrix_fails_closed(): void
    {
        $service = new OrganizationAuthorizationService();
        $checks = 0;

        foreach (['portal-a', 'portal-b'] as $actorText) {
            foreach (['org-a', 'org-b', null] as $selectedText) {
                foreach (['org-a', 'org-b', null] as $resourceText) {
                    foreach (['portal-a', 'portal-b'] as $memberActorText) {
                        foreach (['org-a', 'org-b'] as $memberOrgText) {
                            foreach (MembershipStatus::cases() as $status) {
                                foreach (['services.view', 'billing.view', '*', ''] as $requestedPermission) {
                                    $context = new AuthorizationContext(
                                        new PortalUserId($actorText),
                                        $selectedText === null ? null : new OrganizationId($selectedText),
                                        $resourceText === null ? null : new OrganizationId($resourceText),
                                        $requestedPermission,
                                    );

                                    $membership = new OrganizationMembership(
                                        new PortalUserId($memberActorText),
                                        new OrganizationId($memberOrgText),
                                        OrganizationRole::Owner,
                                        $status,
                                        [Permission::ViewServices],
                                    );

                                    $expected = $status === MembershipStatus::Active
                                        && $actorText === $memberActorText
                                        && $selectedText !== null
                                        && $selectedText === $resourceText
                                        && $selectedText === $memberOrgText
                                        && $requestedPermission === Permission::ViewServices->value;

                                    self::assertSame(
                                        $expected,
                                        $service->decide($context, $membership)->allowed,
                                        sprintf(
                                            'actor=%s selected=%s resource=%s membership=%s/%s status=%s permission=%s',
                                            $actorText,
                                            $selectedText ?? 'none',
                                            $resourceText ?? 'none',
                                            $memberActorText,
                                            $memberOrgText,
                                            $status->value,
                                            $requestedPermission,
                                        ),
                                    );
                                    $checks++;
                                }
                            }
                        }
                    }
                }
            }
        }

        self::assertSame(1152, $checks);
    }

    public function test_no_membership_never_authorizes_a_requested_action(): void
    {
        $service = new OrganizationAuthorizationService();

        foreach (Permission::cases() as $permission) {
            self::assertFalse($service->decide(
                new AuthorizationContext(
                    new PortalUserId('portal-a'),
                    new OrganizationId('org-a'),
                    new OrganizationId('org-a'),
                    $permission->value,
                ),
                null,
            )->allowed);
        }
    }

    public function test_equal_text_ids_from_different_issuers_are_not_distinguishable_here(): void
    {
        // This is an explicit LIMITATION, not a claim that CRM can log into Portal.
        // A future Portal-only authenticated resolver MUST reject external identities
        // before creating these value objects; this pure evaluator cannot check issuer.
        $sameText = 'shared-opaque-id';
        $organization = new OrganizationId('org-a');
        $unverifiedActor = new PortalUserId($sameText);
        $unverifiedMembership = new OrganizationMembership(
            new PortalUserId($sameText),
            $organization,
            OrganizationRole::Member,
            MembershipStatus::Active,
            [Permission::ViewServices],
        );

        self::assertTrue((new OrganizationAuthorizationService())->decide(
            new AuthorizationContext(
                $unverifiedActor,
                $organization,
                $organization,
                Permission::ViewServices->value,
            ),
            $unverifiedMembership,
        )->allowed);
    }
}
