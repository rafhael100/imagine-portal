<?php

declare(strict_types=1);

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

final class PortalSeoPermissionConvergenceTest extends TestCase
{
    public function test_both_independent_review_stacks_keep_distinct_closed_permissions(): void
    {
        self::assertSame(
            'commercial.delivery-review.view',
            Permission::ViewCommercialDeliveryReview->value,
        );
        self::assertSame(
            'seo.gsc.connect',
            Permission::ConnectGoogleSearchConsole->value,
        );
        self::assertNotSame(
            Permission::ConnectGoogleSearchConsole,
            Permission::ViewCommercialDeliveryReview,
        );
        self::assertSame(
            count(Permission::cases()),
            count(array_unique(array_column(Permission::cases(), 'value'))),
        );
        self::assertNull(Permission::tryFrom('*'));
        self::assertNull(Permission::tryFrom('seo.gsc.*'));
        self::assertNull(Permission::tryFrom('commercial.delivery-review.*'));
    }

    public function test_vocabulary_does_not_automatically_grant_either_permission(): void
    {
        $actor = new PortalUserId('actor-fixture');
        $organization = new OrganizationId('org-fixture');
        $membership = new OrganizationMembership(
            $actor,
            $organization,
            OrganizationRole::Member,
            MembershipStatus::Active,
            [Permission::ViewServices],
        );
        $auth = new OrganizationAuthorizationService();

        foreach ([
            Permission::ConnectGoogleSearchConsole,
            Permission::ViewCommercialDeliveryReview,
        ] as $requested) {
            $context = new AuthorizationContext(
                $actor,
                $organization,
                $organization,
                $requested->value,
            );
            self::assertFalse($auth->decide($context, $membership)->allowed);
            self::assertFalse($auth->decide($context, null)->allowed);
            self::assertFalse($auth->decide(
                new AuthorizationContext(
                    $actor,
                    $organization,
                    new OrganizationId('other-fixture'),
                    $requested->value,
                ),
                $membership,
            )->allowed);
        }
    }

    public function test_enum_contains_no_cross_product_permissions(): void
    {
        foreach (Permission::cases() as $permission) {
            self::assertStringNotContainsString('*', $permission->value);
            self::assertStringNotContainsString('crm.', $permission->value);
            self::assertStringNotContainsString('universidade.', $permission->value);
        }
    }
}
