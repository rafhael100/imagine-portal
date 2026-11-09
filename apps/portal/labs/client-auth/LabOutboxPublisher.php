<?php

declare(strict_types=1);

namespace App\Labs\ClientAuth;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Transactional fake-event publisher. Only the synthetic CLI test process
 * may call this class. Never delivers e-mails, queues, payments or webhooks.
 */
final class LabOutboxPublisher
{
    public function enqueue(string $actor, string $organization, string $requestId): ?string
    {
        if (! app()->environment('testing')
            || PHP_SAPI !== 'cli'
            || getenv('PORTAL_CLIENT_LAB_ENABLED') !== '1'
            || getenv('DB_DATABASE') !== 'portal_lab'
            || config('database.connections.portal_lab_runtime.username') !== 'portal_lab_runtime') {
            throw new \RuntimeException('Lab publisher is disabled outside PG synthetic test.');
        }

        $db = DB::connection('portal_lab_runtime');

        return $db->transaction(static function () use ($db, $actor, $organization, $requestId): ?string {
            // Same F4 shared lock protocol as the HTTP request writers.
            LabMembershipFence::lockWriter($db, $actor, $organization);

            $grant = $db->table('lab_portal_memberships as m')
                ->join('lab_portal_organizations as o', 'o.id', '=', 'm.org_id')
                ->join('lab_portal_users as u', 'u.id', '=', 'm.user_id')
                ->where('m.org_id', $organization)
                ->where('m.user_id', $actor)
                ->where('m.status', 'active')
                ->where('o.status', 'active')
                ->where('u.status', 'active')
                ->first(['m.revision as membership_revision', 'u.session_revision as account_revision']);

            if ($grant === null) {
                return null;
            }

            $db->select("SELECT set_config('portal.lab_tenant', ?, true)", [$organization]);
            $db->select("SELECT set_config('portal.lab_actor', ?, true)", [$actor]);

            // Source resource is RLS-scoped, with a composite tenant PK.
            if (! $db->table('lab_portal_requests')
                ->where('org_id', $organization)->where('id', $requestId)->exists()) {
                return null;
            }

            $eventType = 'request.received.synthetic';
            $id = (string) Str::uuid();

            $inserted = $db->table('lab_portal_outbox')->insertOrIgnore([
                'org_id' => $organization,
                'id' => $id,
                'actor_id' => $actor,
                'request_id' => $requestId,
                'event_type' => $eventType,
                'membership_revision' => (int) $grant->membership_revision,
                'account_revision' => (int) $grant->account_revision,
                'status' => 'pending',
                'attempts' => 0,
            ]);

            if ($inserted === 1) {
                return $id;
            }

            // An old delivered/blocked event must never be re-enqueued with
            // the same request+event type, even after membership reactivation.
            $existing = $db->table('lab_portal_outbox')
                ->where('org_id', $organization)
                ->where('request_id', $requestId)
                ->where('event_type', $eventType)
                ->first(['id']);

            return $existing?->id;
        });
    }
}
