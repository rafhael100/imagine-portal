<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\PortalAccess\Authority\PortalSessionPurpose;
use App\Models\PortalAuthSession;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * INT-F162: end-to-end model/PG schema parity in disposable PostgreSQL 18.
 * Runs ONLY under a controlled synthetic CI environment, never customer DB.
 */
final class PortalTypedSessionPg18Test extends TestCase
{
    private const ACTOR = '11111111-1111-4111-8111-111111111111';
    private const ORG = '22222222-2222-4222-8222-222222222222';
    private const MEMBER = '33333333-3333-4333-8333-333333333333';
    private const BUSINESS = '55555555-5555-4555-8555-555555555551';
    private const INVITATION = '55555555-5555-4555-8555-555555555552';
    private const LEGACY = '55555555-5555-4555-8555-555555555553';

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('PORTAL_F162_PG_LAB') !== 'NON_OPERATIONAL_F162') {
            self::markTestSkipped('F162 needs a disposable PG18 CI database.');
        }
        if (DB::getDriverName() !== 'pgsql'
            || config('database.connections.pgsql.host') !== '127.0.0.1'
            || config('database.connections.pgsql.database') !== 'portal_f162_lab'
            || config('database.connections.pgsql.username') !== 'postgres') {
            self::fail('F162 must never run against a shared or production database.');
        }
    }

    private function rejectConstraint(callable $action, string $description): void
    {
        try {
            $action();
        } catch (QueryException $e) {
            self::assertSame('23514', (string) $e->getCode(), $description);
            return;
        }
        self::fail('PostgreSQL accepted forbidden session purpose: '.$description);
    }

    public function test_persisted_purposes_are_typed_and_f113_rejects_mutation_or_excess_ttl(): void
    {
        self::assertSame(0, DB::table('portal_auth_sessions')->count());
        self::assertSame(0, DB::table('portal_memberships')->count());
        self::assertSame(0, DB::table('portal_organizations')->count());

        // The only writes occur in the disposable PG18 runner, as a test
        // database admin; the Laravel runtime still has no RLS ALLOW role.
        try {
            DB::insert(<<<'SQL'
INSERT INTO public.portal_identities
  (id,email_normalized,status,security_revision,created_at,updated_at)
VALUES ('11111111-1111-4111-8111-111111111111',
  'f162@example.invalid','active',1,now(),now())
SQL);
            DB::insert(<<<'SQL'
INSERT INTO public.portal_organizations
  (id,reference,display_name,status,security_revision,created_at,updated_at)
VALUES ('22222222-2222-4222-8222-222222222222',
  'f162-demo','F162 synthetic only','active',1,now(),now())
SQL);
            DB::insert(<<<'SQL'
INSERT INTO public.portal_memberships
  (id,identity_id,organization_id,role,status,membership_revision,created_at,updated_at)
VALUES ('33333333-3333-4333-8333-333333333333',
  '11111111-1111-4111-8111-111111111111',
  '22222222-2222-4222-8222-222222222222',
  'member','active',1,now(),now())
SQL);
            DB::insert(<<<'SQL'
INSERT INTO public.portal_auth_sessions
  (id,session_sha256,identity_id,organization_id,status,
   identity_revision,membership_revision,organization_revision,
   session_purpose,issued_at,expires_at,created_at,updated_at)
VALUES
  ('55555555-5555-4555-8555-555555555551',
   'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
   '11111111-1111-4111-8111-111111111111',
   '22222222-2222-4222-8222-222222222222',
   'pending_mfa',1,1,1,'business',now(),now()+interval '45 minutes',now(),now()),
  ('55555555-5555-4555-8555-555555555552',
   'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
   '11111111-1111-4111-8111-111111111111',
   '22222222-2222-4222-8222-222222222222',
   'pending_mfa',1,1,1,'invitation_only',now(),now()+interval '20 minutes',now(),now()),
  ('55555555-5555-4555-8555-555555555553',
   'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc',
   '11111111-1111-4111-8111-111111111111',
   '22222222-2222-4222-8222-222222222222',
   'pending_mfa',1,1,NULL,NULL,now(),now()+interval '20 minutes',now(),now())
SQL);

            $business = PortalAuthSession::query()->findOrFail(self::BUSINESS);
            $invitation = PortalAuthSession::query()->findOrFail(self::INVITATION);
            $legacy = PortalAuthSession::query()->findOrFail(self::LEGACY);
            self::assertSame(PortalSessionPurpose::Business, $business->session_purpose);
            self::assertSame(PortalSessionPurpose::InvitationOnly, $invitation->session_purpose);
            self::assertSame('business', $business->toArray()['session_purpose']);
            self::assertSame('invitation_only', $invitation->toArray()['session_purpose']);
            self::assertNull($legacy->session_purpose);
            self::assertNull($legacy->organization_revision);
            self::assertSame(1, $business->organization_revision);
            self::assertSame(1, $invitation->organization_revision);
            self::assertArrayNotHasKey('session_sha256', $business->toArray());

            $this->rejectConstraint(static function (): void {
                DB::update(
                    "UPDATE public.portal_auth_sessions SET session_purpose='business'
                     WHERE id='55555555-5555-4555-8555-555555555552'"
                );
            }, 'invitation cannot upgrade to business');
            $this->rejectConstraint(static function (): void {
                DB::update(
                    "UPDATE public.portal_auth_sessions SET session_purpose='business'
                     WHERE id='55555555-5555-4555-8555-555555555553'"
                );
            }, 'legacy NULL cannot become business');
            $this->rejectConstraint(static function (): void {
                DB::update(
                    "UPDATE public.portal_auth_sessions SET session_purpose='invitation_only'
                     WHERE id='55555555-5555-4555-8555-555555555551'"
                );
            }, 'business cannot become invitation');
            $this->rejectConstraint(static function (): void {
                DB::update(
                    "UPDATE public.portal_auth_sessions SET expires_at=issued_at+interval '2 hours'
                     WHERE id='55555555-5555-4555-8555-555555555552'"
                );
            }, 'invitation lifetime limited to one hour');
            $this->rejectConstraint(static function (): void {
                DB::update(
                    "UPDATE public.portal_auth_sessions SET expires_at=issued_at+interval '25 hours'
                     WHERE id='55555555-5555-4555-8555-555555555551'"
                );
            }, 'business lifetime limited to one day');

            self::assertSame(0, (int) DB::table('pg_policies')
                ->where('schemaname', 'public')
                ->where('tablename', 'portal_auth_sessions')->count());
            self::assertSame(3, DB::table('portal_auth_sessions')->count());
        } finally {
            DB::table('portal_auth_sessions')->whereIn('id', [
                self::BUSINESS, self::INVITATION, self::LEGACY,
            ])->delete();
            DB::table('portal_memberships')->where('id', self::MEMBER)->delete();
            DB::table('portal_identities')->where('id', self::ACTOR)->delete();
            DB::table('portal_organizations')->where('id', self::ORG)->delete();
        }

        self::assertSame(0, DB::table('portal_auth_sessions')->count());
    }
}
