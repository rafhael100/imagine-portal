<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Identity\PortalUserId;
use App\Domain\Organizations\OrganizationId;
use App\Domain\ServicePortfolio\UniversityInteropIdentifiers;
use PHPUnit\Framework\TestCase;

final class UniversityInteropIdentifiersTest extends TestCase
{
    public function test_portal_tenant_schema_accepts_128_but_university_stops_at_80(): void
    {
        foreach ([1, 79, 80] as $size) {
            $organization = new OrganizationId(str_repeat('o', $size));
            self::assertTrue(UniversityInteropIdentifiers::supportsOrganization($organization));
        }
        foreach ([81, 120, 128] as $size) {
            // Valid generic Portal tenant: must NEVER be truncated/aliased.
            $organization = new OrganizationId(str_repeat('o', $size));
            self::assertFalse(UniversityInteropIdentifiers::supportsOrganization($organization));
        }
    }

    public function test_portal_subject_schema_accepts_128_but_university_stops_at_120(): void
    {
        foreach ([1, 119, 120] as $size) {
            $subject = new PortalUserId(str_repeat('p', $size));
            self::assertTrue(UniversityInteropIdentifiers::supportsSubject($subject));
        }
        foreach ([121, 127, 128] as $size) {
            $subject = new PortalUserId(str_repeat('p', $size));
            self::assertFalse(UniversityInteropIdentifiers::supportsSubject($subject));
        }
        self::assertFalse(UniversityInteropIdentifiers::mayIssueGrant());
    }
}
