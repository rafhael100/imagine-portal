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
