<?php

declare(strict_types=1);

namespace App\Labs\ClientAuth;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Imagine\Protocol\Lab\SyntheticS2SProtocol;
use Throwable;

require_once __DIR__.'/../performance-contract/SyntheticS2SProtocol.php';

/**
 * F10: TEST-ONLY exercise linking a verified synthetic MFA session to a
 * fictional Performance service. No HTTP routes, provider, queue or worker.
 *
 * This is NOT operational S2S authorization. Identity, grant revisions,
 * bindings and signing secret are test data, not production authority.
 */
final class LabPerformanceMfaIssuer
{
    private const BINDINGS = [
        'org-b' => [
            'service-b' => [
                'organization' => 'org-demo-b',
                'tenant' => 'perf-demo-b',
                'service' => 'svc-demo-b',
                'grant_revision' => 1,
                'membership_revision' => 1,
                'account_revision' => 1,
            ],
        ],
    ];

    /** @return array<string,mixed>|null */
    public function issue(
        Request $request,
        string $organization,
        string $service,
        string $requestId,
        string $nonce,
        int $issuedAt,
        int $expiresAt,
        string $ephemeralTestKey,
    ): ?array {
        if (app()->environment() !== 'testing'
            || PHP_SAPI !== 'cli'
            || getenv('PORTAL_CLIENT_LAB_ENABLED') !== '1'
            || getenv('DB_DATABASE') !== 'portal_lab'
            || config('database.connections.portal_lab_runtime.username') !== 'portal_lab_runtime'
            || config('database.connections.portal_lab_runtime.database') !== 'portal_lab'
            || strlen($ephemeralTestKey) < 32) {
            return null;
        }

        // The caller does not supply an actor, tenant, service grant revision,
        // account revision or snapshot digest.
        $binding = self::BINDINGS[$organization][$service] ?? null;
        if ($binding === null) {
            return null;
        }

        $fixture = dirname(__DIR__).'/performance-contract/performance-dashboard-v1.json';
        if (!is_file($fixture) || is_link($fixture) || !is_readable($fixture)
            || filesize($fixture) > 32768) {
            return null;
        }
        $snapshot = file_get_contents($fixture);
        if (!is_string($snapshot) || $snapshot === '') {
            return null;
        }
        $digest = hash('sha256', $snapshot);

        try {
            $db = DB::connection('portal_lab_runtime');
            if (($db->selectOne('SELECT current_user AS role')->role ?? null) !== 'portal_lab_runtime') {
                return null;
            }

            // LabSecureLogin checks MFA, session revocation, expiry and the
            // account revision against PostgreSQL, not a client actor ID.
            $actor = app(LabSecureLogin::class)->actor($request);
            if ($actor === null) {
                return null;
            }

            return $db->transaction(static function () use (
                $db, $actor, $request, $organization, $service, $binding,
                $requestId, $nonce, $issuedAt, $expiresAt, $digest, $ephemeralTestKey
            ): ?array {
                // Participating Portal revocations must take the incompatible
                // exclusive F4 lock before changing membership state.
                LabMembershipFence::lockWriter($db, $actor, $organization);

                // Re-check session after the lock; actor can still be revoked
                // by a non-participating privileged SQL writer, so this is a
                // laboratory proof, not a production guarantee.
                if (app(LabSecureLogin::class)->actor($request) !== $actor) {
                    return null;
                }

                $membership = $db->table('lab_portal_memberships as m')
                    ->join('lab_portal_users as u', 'u.id', '=', 'm.user_id')
                    ->join('lab_portal_organizations as o', 'o.id', '=', 'm.org_id')
                    ->where('m.org_id', $organization)
                    ->where('m.user_id', $actor)
                    ->where('m.status', 'active')
                    ->where('u.status', 'active')
                    ->where('o.status', 'active')
                    ->where('m.revision', $binding['membership_revision'])
                    ->where('u.session_revision', $binding['account_revision'])
                    ->first(['m.revision']);
                if ($membership === null) {
                    return null;
                }

                $db->select("SELECT set_config('portal.lab_tenant', ?, true)", [$organization]);
                $db->select("SELECT set_config('portal.lab_actor', ?, true)", [$actor]);

                // Service is checked with the regular runtime role and RLS.
                // Product authority comes from the fixed lab allowlist,
                // NEVER a display label or a caller-provided tenant.
                if (!$db->table('lab_portal_services')
                    ->where('org_id', $organization)
                    ->where('id', $service)->exists()) {
                    return null;
                }

                // PERF-F11 extra revocation check is enabled ONLY in the new
                // isolated CLI lab. Existing F1-F10 regression tests retain
                // their original schema and behavior when this flag is unset.
                if (getenv('PORTAL_PERF_F11_LAB_ENABLED') === '1') {
                    $validGrant = $db->table('lab_portal_perf_authority')
                        ->where('org_id', $organization)
                        ->where('service_id', $service)
                        ->where('organization_ref', $binding['organization'])
                        ->where('tenant_ref', $binding['tenant'])
                        ->where('service_ref', $binding['service'])
                        ->where('min_revision', $binding['grant_revision'])
                        ->where('active', true)->exists();
                    if (!$validGrant) {
                        return null;
                    }
                }

                return SyntheticS2SProtocol::mintForLab(
                    $binding['organization'],
                    $binding['tenant'],
                    $binding['service'],
                    $requestId,
                    $nonce,
                    $issuedAt,
                    $expiresAt,
                    $binding['grant_revision'],
                    $digest,
                    $ephemeralTestKey
                );
            });
        } catch (Throwable) {
            // Includes invalid envelope, DB/network errors or missing session.
            // There is no fallback to an unverified actor or another SQL role.
            return null;
        }
    }
}
