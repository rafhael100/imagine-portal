<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Authorization\Permission;
use PHPUnit\Framework\TestCase;

/**
 * INT-F151: cross-stack release canary. The enum describes a vocabulary; it
 * must not be mistaken for a PostgreSQL grant or runtime authorization.
 *
 * This is a source/schema CONTRACT test, not a live DB privilege probe.
 * Independent AppSec/DBA review and authenticated grant issuers are required.
 */
final class PortalIntegratedPermissionRolloutFenceTest extends TestCase
{
    /** @return list<string> */
    private function enumValues(): array
    {
        return array_map(
            static fn (Permission $permission): string => $permission->value,
            Permission::cases(),
        );
    }

    private function migration(string $suffix): string
    {
        $path = __DIR__ . '/../../database/migrations/' . $suffix;
        $contents = file_get_contents($path);
        self::assertIsString($contents, $path);
        return $contents;
    }

    public function test_closed_vocabulary_and_schema_eligible_subset_are_deliberately_different(): void
    {
        $known = $this->enumValues();
        self::assertSame([
            'commercial.delivery-review.view',
            'services.view',
            'services.manage',
            'billing.view',
            'members.manage',
            'seo.gsc.connect',
        ], $known);
        self::assertCount(count(array_unique($known)), $known);

        // The only NEW permission permitted by the incremental GSC SQL
        // CHECK constraint is seo.gsc.connect. Commercial review is pending
        // a separate AppSec/DBA decision, NOT eligible for a real DB grant.
        $schemaEligible = [
            'services.view', 'services.manage', 'billing.view',
            'members.manage', 'seo.gsc.connect',
        ];
        self::assertSame(
            ['commercial.delivery-review.view'],
            array_values(array_diff($known, $schemaEligible)),
        );
        self::assertNull(Permission::tryFrom('seo.gsc.*'));
        self::assertNull(Permission::tryFrom('commercial.delivery-review.*'));
    }

    public function test_database_migrations_do_not_enable_unreviewed_commercial_review_grants(): void
    {
        $initial = $this->migration(
            '2026_11_09_000002_create_portal_identity_foundation.php',
        );
        $seo = $this->migration(
            '2026_11_10_000007_allow_seo_gsc_connect_grant.php',
        );

        self::assertStringContainsString(
            "portal_grant_permission_ck CHECK (permission_code IN ('services.view','services.manage','billing.view','members.manage'))",
            $initial,
        );
        self::assertStringContainsString(
            "'services.view','services.manage','billing.view','members.manage',",
            $seo,
        );
        self::assertStringContainsString("'seo.gsc.connect'))", $seo);
        self::assertStringNotContainsString(
            "'commercial.delivery-review.view'", $initial,
        );
        self::assertStringNotContainsString(
            "'commercial.delivery-review.view'", $seo,
        );
        self::assertStringContainsString(
            "->where('permission_code', 'seo.gsc.connect')", $seo,
        );
        self::assertStringContainsString(
            'rollback refused', $seo,
        );
    }

    public function test_no_schema_migration_auto_issues_application_grants_or_public_access(): void
    {
        $paths = glob(__DIR__ . '/../../database/migrations/*.php');
        self::assertIsArray($paths);
        self::assertNotEmpty($paths);
        foreach ($paths as $path) {
            $contents = file_get_contents($path);
            self::assertIsString($contents, $path);
            // Source-only canary: no migration is allowed to auto-insert
            // membership grants while trusted issuer is absent.
            self::assertDoesNotMatchRegularExpression(
                '~(?:insert\s+into|update)\s+(?:public\.)?portal_membership_grants\b~i',
                $contents,
                $path,
            );
            self::assertDoesNotMatchRegularExpression(
                '~DB::table\([\x22\x27]portal_membership_grants[\x22\x27]\)\s*->\s*(?:insert|update|upsert)\s*\(~i',
                $contents,
                $path,
            );
        }
    }

    public function test_runtime_default_denial_is_not_replaced_by_a_recognized_enum_value(): void
    {
        $provider = file_get_contents(
            __DIR__ . '/../../app/Providers/AppServiceProvider.php',
        );
        self::assertIsString($provider);
        self::assertStringContainsString(
            'DenyingPortalAuthorities::class', $provider,
        );
        foreach ([
            'PortalSessionAuthority::class',
            'PortalMembershipAuthority::class',
            'PortalResourceAuthority::class',
        ] as $authority) {
            self::assertStringContainsString($authority, $provider);
        }
        self::assertStringNotContainsString(
            'ConnectGoogleSearchConsole', $provider,
        );
        self::assertStringNotContainsString(
            'ViewCommercialDeliveryReview', $provider,
        );
    }
}
