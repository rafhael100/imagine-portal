<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity;

use PDO;
use RuntimeException;
use Throwable;

/**
 * F303: read-only Portal browser permission PRE-FLIGHT.
 *
 * A true result means only that the current database transaction observed a
 * valid business session in the selected tenant with a named membership grant.
 * It does NOT prove ownership of a requested business resource, and MUST NOT
 * be bound to a Laravel HTTP/resource authorization interface by itself.
 *
 * Unlike a bearer/session issuer, this class is never container-bound and
 * uses only the dedicated restricted Portal identity PostgreSQL connection.
 */
final readonly class PostgresPortalPermissionPreflight
{
    private const PERMISSIONS = [
        'services.view',
        'services.manage',
        'billing.view',
        'members.manage',
    ];

    public function __construct(private PDO $db, private string $database)
    {
        $this->ready();
    }

    public function __debugInfo(): array
    {
        return ['backend' => 'Portal PostgreSQL permission preflight'];
    }

    public function __serialize(): array
    {
        throw new RuntimeException('Permission preflight cannot be serialized.');
    }

    public function allows(string $businessReference, string $organization, string $permission): bool
    {
        // Reject caller-provided IDs and wildcards before sending any SQL.
        if (preg_match('/\\A[a-f0-9]{64}\\z/D', $businessReference) !== 1
            || preg_match('/\\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\\z/D', $organization) !== 1
            || !in_array($permission, self::PERMISSIONS, true)) {
            return false;
        }

        $this->ready();
        try {
            $q = $this->db->prepare('SELECT public.portal_browser_permission_preflight(?,CAST(? AS uuid),?)');
            $q->execute([hash('sha256', $businessReference), $organization, $permission]);
            return in_array($q->fetchColumn(), [true, 1, '1', 't'], true);
        } catch (Throwable) {
            // No query, tenant, credential or PDO diagnostic escapes this boundary.
            throw new RuntimeException('Portal permission preflight unavailable.');
        }
    }

    private function ready(): void
    {
        if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql'
            || $this->db->inTransaction()) {
            throw new RuntimeException('Dedicated autocommit Portal permission connection required.');
        }

        try {
            $q = $this->db->query("SELECT current_database() AS db,current_user AS actor,session_user AS login,
                rolsuper,rolbypassrls,rolcreatedb,rolcreaterole,rolinherit,
                EXISTS(SELECT 1 FROM pg_auth_members WHERE member=r.oid) AS inherited
                FROM pg_roles r WHERE rolname=current_user");
            $row = $q->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            throw new RuntimeException('Portal permission preflight unavailable.');
        }

        if (!is_array($row) || $row['db'] !== $this->database
            || $row['actor'] !== 'portal_browser_runtime'
            || $row['login'] !== 'portal_browser_runtime') {
            throw new RuntimeException('Dedicated Portal authentication role required.');
        }
        foreach (['rolsuper','rolbypassrls','rolcreatedb','rolcreaterole','rolinherit','inherited'] as $field) {
            if (in_array($row[$field] ?? null, [true, 1, '1', 't'], true)) {
                throw new RuntimeException('Privileged authentication role refused.');
            }
        }
    }
}
