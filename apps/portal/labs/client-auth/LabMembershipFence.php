<?php

declare(strict_types=1);

namespace App\Labs\ClientAuth;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * F4 — Experimental PG transaction-level advisory grant lock.
 *
 * All *participating* synthetic writes acquire SHARED lock BEFORE checking
 * active membership. A synthetic revoker acquires EXCLUSIVE lock before
 * changing membership status. The lock lives until COMMIT/ROLLBACK.
 *
 * Crucial limitation: an administrator/worker using direct UPDATE without
 * the lock can bypass this protocol. This is not proven revocation safety for
 * production, nor recovery from a compromised writer/database administrator.
 */
final class LabMembershipFence
{
    public static function scope(string $actor, string $organization): string
    {
        if ($actor === '' || $organization === '') {
            throw new \InvalidArgumentException('Empty synthetic grant scope.');
        }

        return 'imagine-portal-lab-grant-v1:'.$organization.':'.$actor;
    }

    private static function assertTransaction(ConnectionInterface $connection): void
    {
        if ($connection->transactionLevel() < 1) {
            throw new \RuntimeException('Synthetic grant fence requires an active transaction.');
        }
    }

    public static function lockWriter(ConnectionInterface $connection, string $actor, string $organization): void
    {
        self::assertTransaction($connection);
        $connection->select(
            'SELECT pg_advisory_xact_lock_shared(hashtextextended(?, 0))',
            [self::scope($actor, $organization)],
        );
    }

    public static function lockRevoker(ConnectionInterface $connection, string $actor, string $organization): void
    {
        self::assertTransaction($connection);
        $connection->select(
            'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))',
            [self::scope($actor, $organization)],
        );
    }

    /**
     * Test-only participating revocation. Never uses the untrusted runtime
     * user to modify memberships. The admin connection is permitted solely
     * inside the disposable portal_lab database in CLI testing.
     */
    public static function revoke(string $actor, string $organization): int
    {
        if (! app()->environment('testing')
            || PHP_SAPI !== 'cli'
            || getenv('PORTAL_CLIENT_LAB_ENABLED') !== '1') {
            throw new \RuntimeException('Synthetic revocation is disabled.');
        }

        $admin = DB::connection('pgsql');
        $dbName = $admin->selectOne('SELECT current_database() AS name')->name;
        if ($dbName !== 'portal_lab') {
            throw new \RuntimeException('Refusing to revoke outside disposable lab.');
        }

        return $admin->transaction(static function () use ($admin, $actor, $organization): int {
            self::lockRevoker($admin, $actor, $organization);

            return $admin->table('lab_portal_memberships')
                ->where('org_id', $organization)
                ->where('user_id', $actor)
                ->update(['status' => 'revoked', 'revision' => DB::raw('revision + 1')]);
        });
    }
}
