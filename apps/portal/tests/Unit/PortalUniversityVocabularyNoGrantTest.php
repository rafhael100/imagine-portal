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

/**
 * UNI-PORTAL V0: only vocabulary and synthetic deny checks.
 * No issuer, database grant, HTTP access or verified service eligibility.
 */
final class PortalUniversityVocabularyNoGrantTest extends TestCase
{
    public function test_typed_permission_names_are_distinct_and_wildcards_denied(): void
    {
        self::assertSame('university:view', Permission::ViewUniversity->value);
        self::assertSame('university:manage', Permission::ManageUniversity->value);
        self::assertNotSame(Permission::ViewUniversity, Permission::ManageUniversity);
        self::assertNull(Permission::tryFrom('university:*'));
        self::assertNull(Permission::tryFrom('universidade:view'));
    }

    public function test_owner_without_explicit_university_grants_is_denied(): void
    {
        $actor = new PortalUserId('synthetic-actor');
        $company = new OrganizationId('synthetic-company');
        $member = new OrganizationMembership(
            $actor, $company, OrganizationRole::Owner, MembershipStatus::Active,
            [Permission::ViewServices, Permission::ManageMembers],
        );
        $authority = new OrganizationAuthorizationService();
        foreach ([Permission::ViewUniversity, Permission::ManageUniversity] as $permission) {
            $context = new AuthorizationContext($actor, $company, $company, $permission->value);
            self::assertFalse($authority->decide($context, $member)->allowed);
            self::assertFalse($authority->decide($context, null)->allowed);
        }
    }

    public function test_db_grants_and_http_access_are_not_enabled_by_vocabulary(): void
    {
        $root = dirname(__DIR__, 2);
        $provider = file_get_contents($root.'/app/Providers/AppServiceProvider.php');
        $routes = file_get_contents($root.'/routes/web.php');
        self::assertIsString($provider);
        self::assertIsString($routes);
        self::assertStringContainsString('DenyingPortalAuthorities::class', $provider);
        self::assertStringNotContainsString('University', $provider);
        self::assertStringNotContainsString('/universidade/private', $routes);

        $migrations = glob($root.'/database/migrations/*.php');
        self::assertIsArray($migrations);
        self::assertNotEmpty($migrations);
        foreach ($migrations as $path) {
            $body = file_get_contents($path);
            self::assertIsString($body);
            self::assertStringNotContainsString("'university:view'", $body, $path);
            self::assertStringNotContainsString("'university:manage'", $body, $path);
        }
    }
}
