<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\PortalAccess\Authority\PortalSessionPurpose;
use App\Models\PortalAuthSession;
use Tests\TestCase;
use ValueError;

/**
 * INT-F161: purely in-memory Eloquent cast tests.
 * No login, database query, user grant, cookie or production authority.
 */
final class PortalTypedSessionPersistenceTest extends TestCase
{
    private function syntheticRow(mixed $purpose, mixed $orgRevision): PortalAuthSession
    {
        $session = new PortalAuthSession();
        $session->setRawAttributes([
            'id' => '55555555-5555-4555-8555-555555555555',
            'session_sha256' => str_repeat('a', 64),
            'session_purpose' => $purpose,
            'organization_revision' => $orgRevision,
        ], true);

        return $session;
    }

    public function test_business_session_is_typed_and_org_revision_is_integer(): void
    {
        $session = $this->syntheticRow('business', '4');

        self::assertSame(PortalSessionPurpose::Business, $session->session_purpose);
        self::assertSame(4, $session->organization_revision);
        self::assertSame('business', $session->toArray()['session_purpose']);
        self::assertArrayNotHasKey('session_sha256', $session->toArray());
    }

    public function test_invitation_only_purpose_never_becomes_business_by_cast(): void
    {
        $session = $this->syntheticRow('invitation_only', '12');

        self::assertSame(PortalSessionPurpose::InvitationOnly, $session->session_purpose);
        self::assertNotSame(PortalSessionPurpose::Business, $session->session_purpose);
        self::assertSame(12, $session->organization_revision);
    }

    public function test_legacy_null_purpose_and_revision_remain_null(): void
    {
        $session = $this->syntheticRow(null, null);
        self::assertNull($session->session_purpose);
        self::assertNull($session->organization_revision);
    }

    public function test_unknown_or_untrimmed_database_purpose_fails_instead_of_becoming_business(): void
    {
        foreach (['oauth', 'business ', '*', '', 'Business'] as $invalid) {
            $session = $this->syntheticRow($invalid, 1);
            try {
                $unused = $session->session_purpose;
                unset($unused);
            } catch (ValueError) {
                continue;
            }
            self::fail('Unrecognized session purpose unexpectedly hydrated: '.$invalid);
        }
    }

    public function test_caller_cannot_mass_assign_session_issuer_material(): void
    {
        $session = new PortalAuthSession();
        self::assertSame(['*'], $session->getGuarded());
        self::assertContains('session_sha256', $session->getHidden());
    }
}
