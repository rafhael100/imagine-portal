<?php

declare(strict_types=1);

namespace App\Labs\ClientAuth;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Throwable;

/**
 * Lab-only Portal session and tenant gateway. This is NOT an approved
 * operational authenticator: no MFA, abuse controls, durable session store,
 * concurrent revocation fence, or anti-rollback authority has been proven.
 */
final class LabPortalGateway
{
    private function database(): ConnectionInterface
    {
        // Never substitute the admin/default connection when the runtime role
        // is missing; that would silently bypass RLS in privileged CI setup.
        if (config('database.connections.portal_lab_runtime') === null) {
            throw new \RuntimeException('Missing isolated lab runtime connection.');
        }

        return DB::connection('portal_lab_runtime');
    }

    /** @return array{id:string, revision:int}|null */
    public function authenticate(string $email, string $password): ?array
    {
        $record = $this->database()->table('lab_portal_users')
            ->where('email', mb_strtolower(trim($email)))->first();

        if ($record === null
            || $record->status !== 'active'
            || ! Hash::check($password, $record->password_hash)) {
            return null;
        }

        return ['id' => $record->id, 'revision' => (int) $record->session_revision];
    }

    public function actor(Request $request): ?string
    {
        $id = $request->session()->get('portal_lab_actor');
        $revision = $request->session()->get('portal_lab_revision');

        if (! is_string($id)
            || $id === ''
            || strlen($id) > 64
            || ! is_int($revision)
            || $revision < 1) {
            return null;
        }

        $record = $this->database()->table('lab_portal_users')
            ->where('id', $id)
            ->where('status', 'active')
            ->where('session_revision', $revision)->first(['id']);

        return $record === null ? null : $id;
    }

    /** @return list<array{id:string,name:string}> */
    public function organizations(string $actor): array
    {
        $rows = $this->database()->table('lab_portal_memberships as m')
            ->join('lab_portal_organizations as o', 'o.id', '=', 'm.org_id')
            ->where('m.user_id', $actor)
            ->where('m.status', 'active')
            ->where('o.status', 'active')
            ->orderBy('o.id')
            ->get(['o.id', 'o.name']);

        return $rows->map(static fn ($row): array => [
            'id' => $row->id,
            'name' => $row->name,
        ])->all();
    }

    /**
     * @return list<array{id:string,label:string}>|null
     * null means no verified active membership. SQL RLS is checked again
     * inside the same transaction against tenant+actor local GUCs.
     */
    public function services(string $actor, string $org): ?array
    {
        $db = $this->database();

        return $db->transaction(static function () use ($db, $actor, $org): ?array {
            $membership = $db->table('lab_portal_memberships as m')
                ->join('lab_portal_organizations as o', 'o.id', '=', 'm.org_id')
                ->where('m.user_id', $actor)
                ->where('m.org_id', $org)
                ->where('m.status', 'active')
                ->where('o.status', 'active')->exists();

            if (! $membership) {
                return null;
            }

            // Local to the transaction, never SET (which leaks through pools).
            // A compromised app could forge GUCs: not a security proof of
            // a compromised Z2 and not operationally homologated.
            $db->select("SELECT set_config('portal.lab_tenant', ?, true)", [$org]);
            $db->select("SELECT set_config('portal.lab_actor', ?, true)", [$actor]);

            return $db->table('lab_portal_services')
                ->orderBy('id')
                ->get(['id', 'label'])
                ->map(static fn ($row): array => [
                    'id' => $row->id,
                    'label' => $row->label,
                ])->all();
        });
    }

    /**
     * Lab-only write proof. Tenant and actor are derived from the verified
     * Portal session and membership in the HTTP route, never from a JSON body.
     * FK (org_id, service_id) prevents linking a service from a different org.
     * RLS WITH CHECK enforces the same tenant and active membership on insert.
     *
     * @return string|null generated request id, or null when not authorized
     */
    public function submitRequest(string $actor, string $org, string $serviceId, string $title): ?string
    {
        $db = $this->database();

        return $db->transaction(static function () use ($db, $actor, $org, $serviceId, $title): ?string {
            $valid = $db->table('lab_portal_memberships as m')
                ->join('lab_portal_organizations as o', 'o.id', '=', 'm.org_id')
                ->where('m.user_id', $actor)
                ->where('m.org_id', $org)
                ->where('m.status', 'active')
                ->where('o.status', 'active')
                ->exists();

            if (! $valid) {
                return null;
            }

            $db->select("SELECT set_config('portal.lab_tenant', ?, true)", [$org]);
            $db->select("SELECT set_config('portal.lab_actor', ?, true)", [$actor]);

            if (! $db->table('lab_portal_services')
                ->where('org_id', $org)
                ->where('id', $serviceId)->exists()) {
                return null;
            }

            $id = (string) \Illuminate\Support\Str::uuid();
            $db->table('lab_portal_requests')->insert([
                'org_id' => $org,
                'id' => $id,
                'service_id' => $serviceId,
                'title' => $title,
                'status' => 'received',
            ]);

            return $id;
        });
    }

    /** @return list<array{id:string,title:string,status:string}>|null */
    public function requests(string $actor, string $org): ?array
    {
        $db = $this->database();

        return $db->transaction(static function () use ($db, $actor, $org): ?array {
            $allowed = $db->table('lab_portal_memberships as m')
                ->join('lab_portal_organizations as o', 'o.id', '=', 'm.org_id')
                ->where('m.user_id', $actor)
                ->where('m.org_id', $org)
                ->where('m.status', 'active')
                ->where('o.status', 'active')->exists();
            if (! $allowed) {
                return null;
            }

            $db->select("SELECT set_config('portal.lab_tenant', ?, true)", [$org]);
            $db->select("SELECT set_config('portal.lab_actor', ?, true)", [$actor]);

            return $db->table('lab_portal_requests')
                ->orderBy('id')->get(['id', 'title', 'status'])
                ->map(static fn ($row): array => [
                    'id' => $row->id,
                    'title' => $row->title,
                    'status' => $row->status,
                ])->all();
        });
    }

    /**
     * Uniform failure handling for the lab HTTP routes.
     * Intentionally avoids leaking tenant/user/storage identifiers.
     */
    public static function denyOnFailure(callable $action, int $status = 503): mixed
    {
        try {
            return $action();
        } catch (Throwable) {
            return response()->json(['error' => 'temporarily_unavailable'], $status);
        }
    }
}
