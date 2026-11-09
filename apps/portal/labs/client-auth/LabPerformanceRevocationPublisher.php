<?php

declare(strict_types=1);

namespace App\Labs\ClientAuth;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * PORTAL-PERF-F11 LAB ONLY — service-authorization source and local outbox.
 *
 * This is NOT a public API, production service, tenant-admin authorization
 * system, or network transport. The simulated actor/offer mapping is fixed,
 * and the admin role is usable only in the disposable CLI test database.
 */
final class LabPerformanceRevocationPublisher
{
    /** @return array<string,mixed>|string */
    public function revokeForLab(Request $request, string $organization, string $service): array|string
    {
        if (!app()->environment('testing')
            || PHP_SAPI !== 'cli'
            || getenv('PORTAL_CLIENT_LAB_ENABLED') !== '1'
            || getenv('PORTAL_PERF_F11_LAB_ENABLED') !== '1'
            || getenv('DB_DATABASE') !== 'portal_lab') {
            return 'lab_disabled';
        }

        // Explicit synthetic test authority; cannot revoke arbitrary orgs,
        // services, product types or another tenant. Not a real admin policy.
        $actor = app(LabSecureLogin::class)->actor($request);
        if ($actor !== 'portal-user-b'
            || $organization !== 'org-b'
            || $service !== 'service-b') {
            return 'mock_issuer_denied';
        }

        try {
            $admin = DB::connection('pgsql');
            $dbFacts = $admin->selectOne('SELECT current_database() AS database, current_user AS role');
            if (($dbFacts->database ?? null) !== 'portal_lab'
                || ($dbFacts->role ?? null) !== 'postgres') {
                return 'lab_disabled';
            }

            return $admin->transaction(static function () use (
                $admin, $request, $actor, $organization, $service
            ): array|string {
                // F4 exclusive membership lock opposes F10 shared emission
                // lock, but it only fences participating writers in this DB.
                LabMembershipFence::lockRevoker($admin, $actor, $organization);
                if (app(LabSecureLogin::class)->actor($request) !== $actor) {
                    return 'mock_issuer_denied';
                }

                $authorized = $admin->table('lab_portal_memberships as m')
                    ->join('lab_portal_users as u', 'u.id', '=', 'm.user_id')
                    ->join('lab_portal_organizations as o', 'o.id', '=', 'm.org_id')
                    ->where('m.user_id', $actor)
                    ->where('m.org_id', $organization)
                    ->where('m.status', 'active')
                    ->where('m.revision', 1)
                    ->where('u.status', 'active')
                    ->where('u.session_revision', 1)
                    ->where('o.status', 'active')->exists();

                if (!$authorized
                    || !$admin->table('lab_portal_services')
                        ->where('org_id', $organization)
                        ->where('id', $service)->exists()) {
                    return 'mock_issuer_denied';
                }

                $grant = $admin->table('lab_portal_perf_authority')
                    ->where('org_id', $organization)
                    ->where('service_id', $service)
                    ->lockForUpdate()->first();

                if ($grant === null
                    || $grant->organization_ref !== 'org-demo-b'
                    || $grant->tenant_ref !== 'perf-demo-b'
                    || $grant->service_ref !== 'svc-demo-b') {
                    return 'mock_service_denied';
                }
                if (!(bool) $grant->active) {
                    return 'already_revoked_demo';
                }

                $revision = ((int) $grant->min_revision) + 1;
                if ($revision <= 1) {
                    return 'mock_service_denied';
                }

                $admin->table('lab_portal_perf_authority')
                    ->where('org_id', $organization)
                    ->where('service_id', $service)
                    ->where('min_revision', (int) $grant->min_revision)
                    ->where('active', true)
                    ->update(['min_revision' => $revision, 'active' => false]);

                // A source-generated event ID is not trusted client input.
                // Outbox INSERT and the revocation UPDATE commit together.
                $eventId = 'evt-demo-'.bin2hex(random_bytes(12));
                $event = [
                    'event_id' => $eventId,
                    'service_ref' => $grant->service_ref,
                    'organization_ref' => $grant->organization_ref,
                    'tenant_ref' => $grant->tenant_ref,
                    'source_revision' => $revision,
                    'event_type' => 'service.authorization_changed.synthetic',
                    'status' => 'pending',
                    'attempts' => 0,
                ];
                $admin->table('lab_portal_perf_revocation_outbox')->insert($event);

                return $event;
            });
        } catch (Throwable) {
            // Includes duplicate event, SQL trouble or failed transaction:
            // never emit a successful event without an authority update.
            return 'mock_transaction_denied';
        }
    }

    /** @return array<string,mixed>|null */
    public function currentForLab(string $serviceRef): ?array
    {
        if (!app()->environment('testing')
            || PHP_SAPI !== 'cli'
            || getenv('PORTAL_PERF_F11_LAB_ENABLED') !== '1'
            || getenv('DB_DATABASE') !== 'portal_lab'
            || $serviceRef !== 'svc-demo-b') {
            return null;
        }
        $db = DB::connection('pgsql');
        $row = $db->table('lab_portal_perf_authority')
            ->where('service_ref', $serviceRef)
            ->first(['service_ref','organization_ref','tenant_ref','min_revision','active']);
        return $row === null ? null : [
            'service_ref' => $row->service_ref,
            'organization_ref' => $row->organization_ref,
            'tenant_ref' => $row->tenant_ref,
            'min_revision' => (int) $row->min_revision,
            'active' => (bool) $row->active,
        ];
    }
}
