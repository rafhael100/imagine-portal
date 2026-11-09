<?php

declare(strict_types=1);

namespace App\Labs\F30;

use PDO;
use Throwable;

/**
 * F30 negative drift check for an EXACT PG18 synthetic view and two schemas.
 *
 * The expected hash was captured from pg_get_viewdef() of the known F21
 * fixture on two independent ephemeral PostgreSQL 18 CI runners. This is
 * *not* an external witness, secure deployment signature or general SQL
 * semantic proof. Deparser changes may cause false denials.
 */
final readonly class F30SyntheticViewSchemaInspector
{
    private const F21_VIEW_SHA256 = 'cc952e214081dac8db6ccae625b6714843f98ca85c96fe27d7abbdf5c315fe69';

    public function intact(PDO $pdo): bool
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql'
            || !$pdo->inTransaction()) {
            return false;
        }
        try {
            $result = $pdo->query(
                "SELECT c.relkind AS kind,
                        pg_catalog.pg_get_userbyid(c.relowner) AS owner_name,
                        pg_catalog.pg_get_viewdef(c.oid, false) AS view_sql
                   FROM pg_catalog.pg_class c
                   JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
                  WHERE n.nspname='f21' AND c.relname='service_summary'"
            )->fetchAll(PDO::FETCH_ASSOC);

            if (!is_array($result) || count($result) !== 1
                || $result[0]['kind'] !== 'v'
                || $result[0]['owner_name'] !== 'postgres'
                || !is_string($result[0]['view_sql'])
                || !hash_equals(
                    self::F21_VIEW_SHA256,
                    hash('sha256', $result[0]['view_sql']),
                )) {
                return false;
            }

            $schemas = $pdo->query(
                "SELECT n.nspname AS schema_name,
                        pg_catalog.pg_get_userbyid(n.nspowner) AS owner_name
                   FROM pg_catalog.pg_namespace n
                  WHERE n.nspname IN ('f21','f24')"
            )->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($schemas) || count($schemas) !== 2) {
                return false;
            }
            $schemaNames = [];
            foreach ($schemas as $schema) {
                if (!is_array($schema)
                    || !in_array($schema['schema_name'], ['f21','f24'], true)
                    || $schema['owner_name'] !== 'postgres'
                    || isset($schemaNames[$schema['schema_name']])) {
                    return false;
                }
                $schemaNames[$schema['schema_name']] = true;
            }
            if (count($schemaNames) !== 2) {
                return false;
            }

            // Only USAGE to both read identities is allowed at schema level.
            // CREATE, PUBLIC, other roles and missing grants are denied.
            $acl = $pdo->query(
                "SELECT n.nspname AS schema_name,
                        COALESCE(r.rolname,'PUBLIC') AS grantee_name,
                        a.privilege_type AS privilege
                   FROM pg_catalog.pg_namespace n
                   CROSS JOIN LATERAL pg_catalog.aclexplode(
                       COALESCE(n.nspacl, pg_catalog.acldefault('n', n.nspowner))
                   ) a
                   LEFT JOIN pg_catalog.pg_roles r ON r.oid=a.grantee
                  WHERE n.nspname IN ('f21','f24')
                    AND a.grantee <> n.nspowner"
            )->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($acl) || count($acl) !== 4) {
                return false;
            }
            $allowed = [
                'f21.f21_org_a' => true, 'f21.f21_org_b' => true,
                'f24.f21_org_a' => true, 'f24.f21_org_b' => true,
            ];
            foreach ($acl as $grant) {
                if (!is_array($grant)) {
                    return false;
                }
                $key = ($grant['schema_name'] ?? '').'.'.($grant['grantee_name'] ?? '');
                if (!isset($allowed[$key]) || $grant['privilege'] !== 'USAGE') {
                    return false;
                }
                unset($allowed[$key]);
            }
            return $allowed === [];
        } catch (Throwable) {
            return false;
        }
    }
}
