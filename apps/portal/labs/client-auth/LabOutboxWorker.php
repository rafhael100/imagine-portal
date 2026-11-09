<?php

declare(strict_types=1);

namespace App\Labs\ClientAuth;

use Illuminate\Support\Facades\DB;

/**
 * F7-only consumer. Its sole "effect" is an insert into a synthetic receipt
 * table in the same PostgreSQL transaction. No network, mail or external IO.
 *
 * This is NOT an exactly-once delivery guarantee for real remote systems.
 */
final class LabOutboxWorker
{
    /**
     * @return 'empty'|'blocked'|'delivered'
     */
    public function consumeOne(): string
    {
        if (! app()->environment('testing')
            || PHP_SAPI !== 'cli'
            || getenv('PORTAL_CLIENT_LAB_ENABLED') !== '1'
            || getenv('DB_DATABASE') !== 'portal_lab'
            || config('database.connections.portal_lab_worker.username') !== 'portal_lab_worker'
            || config('database.connections.portal_lab_worker.database') !== 'portal_lab') {
            throw new \RuntimeException('Worker disabled outside the disposable PG lab.');
        }

        $db = DB::connection('portal_lab_worker');

        return $db->transaction(static function () use ($db): string {
            // Read a candidate WITHOUT taking its row lock. Acquire the F4
            // grant lock first, matching the lock ordering used by writers,
            // then take FOR UPDATE SKIP LOCKED on that exact event.
            $candidate = $db->table('lab_portal_outbox')
                ->where('status', 'pending')
                ->orderBy('id')
                ->first(['org_id', 'id', 'actor_id']);

            if ($candidate === null) {
                return 'empty';
            }

            LabMembershipFence::lockWriter(
                $db,
                $candidate->actor_id,
                $candidate->org_id,
            );

            $event = $db->table('lab_portal_outbox')
                ->where('org_id', $candidate->org_id)
                ->where('id', $candidate->id)
                ->where('status', 'pending')
                ->lock('FOR UPDATE SKIP LOCKED')
                ->first();

            if ($event === null) {
                return 'empty';
            }

            // Do NOT trust enqueue-time access or event flags; check the
            // official current membership, organization and user revisions.
            $allowed = $event->event_type === 'request.received.synthetic'
                && $db->table('lab_portal_memberships as m')
                    ->join('lab_portal_organizations as o', 'o.id', '=', 'm.org_id')
                    ->join('lab_portal_users as u', 'u.id', '=', 'm.user_id')
                    ->where('m.org_id', $event->org_id)
                    ->where('m.user_id', $event->actor_id)
                    ->where('m.status', 'active')
                    ->where('o.status', 'active')
                    ->where('u.status', 'active')
                    ->where('m.revision', $event->membership_revision)
                    ->where('u.session_revision', $event->account_revision)
                    ->exists();

            if (! $allowed) {
                $db->table('lab_portal_outbox')
                    ->where('org_id', $event->org_id)->where('id', $event->id)
                    ->update([
                        'status' => 'blocked',
                        'attempts' => ((int) $event->attempts) + 1,
                    ]);

                return 'blocked';
            }

            // "Side effect" exists exclusively inside disposable PostgreSQL,
            // protected by a unique composite FK + primary key. If worker
            // crashes, either BOTH the receipt and status commit or neither.
            $db->table('lab_portal_delivery_receipts')->insertOrIgnore([
                'org_id' => $event->org_id,
                'event_id' => $event->id,
                'effect_type' => 'synthetic.local_receipt_only',
            ]);

            $db->table('lab_portal_outbox')
                ->where('org_id', $event->org_id)->where('id', $event->id)
                ->update([
                    'status' => 'delivered',
                    'attempts' => ((int) $event->attempts) + 1,
                ]);

            return 'delivered';
        });
    }
}
