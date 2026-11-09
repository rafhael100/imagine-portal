<?php

declare(strict_types=1);

namespace App\Labs\F36;

use PDO;
use Throwable;

/**
 * Negative schema-drift guard for F35's FICTIONAL witness acknowledgements.
 * Catalog checks are not external attestation: a privileged database/code
 * operator can change the reference and metadata together.
 */
final readonly class F36AckContractInspector
{
    public function intact(PDO $pdo): bool
    {
        if (getenv('F36_ENABLE_LAB') !== 'ACK_CATALOG_DRIFT_ONLY'
            || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
            return false;
        }

        try {
            $tables = $pdo->query(
                "SELECT c.relrowsecurity AS rls_enabled,
                        c.relforcerowsecurity AS rls_forced,
                        pg_catalog.pg_get_userbyid(c.relowner) AS owner_name,
                        c.relkind AS kind
                   FROM pg_catalog.pg_class c
                   JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace
                  WHERE n.nspname='f35' AND c.relname='applied_event_ack'"
            )->fetchAll(PDO::FETCH_ASSOC);

            if (!is_array($tables) || count($tables) !== 1
                || !$this->pgTrue($tables[0]['rls_enabled'] ?? null)
                || !$this->pgTrue($tables[0]['rls_forced'] ?? null)
                || ($tables[0]['owner_name'] ?? null) !== 'postgres'
                || ($tables[0]['kind'] ?? null) !== 'r') {
                return false;
            }

            $policies = $pdo->query(
                "SELECT p.polname AS policy_name, p.polcmd AS cmd,
                        p.polpermissive AS permissive,
                        pg_catalog.pg_get_expr(p.polqual,p.polrelid) AS predicate,
                        (SELECT string_agg(COALESCE(r.rolname,'PUBLIC'), ',' ORDER BY role_id)
                           FROM unnest(p.polroles) AS selected(role_id)
                           LEFT JOIN pg_catalog.pg_roles r ON r.oid=selected.role_id
                        ) AS roles
                   FROM pg_catalog.pg_policy p
                   JOIN pg_catalog.pg_class c ON c.oid=p.polrelid
                   JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace
                  WHERE n.nspname='f35' AND c.relname='applied_event_ack'"
            )->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($policies) || count($policies) !== 1
                || ($policies[0]['policy_name'] ?? null) !== 'f35_ack_reader'
                || ($policies[0]['cmd'] ?? null) !== 'r'
                || !$this->pgTrue($policies[0]['permissive'] ?? null)
                || ($policies[0]['roles'] ?? null) !== 'f35_ack_worker'
                || !is_string($policies[0]['predicate'] ?? null)
                || $this->normalize($policies[0]['predicate'])
                    !== "session_user='f35_ack_worker'") {
                return false;
            }

            // Reject even non-RLS-exercised write grants and PUBLIC access.
            $grants = $pdo->query(
                "SELECT COALESCE(r.rolname,'PUBLIC') AS grantee,
                        a.privilege_type AS privilege
                   FROM pg_catalog.pg_class c
                   JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace
                   CROSS JOIN LATERAL pg_catalog.aclexplode(
                       COALESCE(c.relacl,pg_catalog.acldefault('r',c.relowner))
                   ) a
                   LEFT JOIN pg_catalog.pg_roles r ON r.oid=a.grantee
                  WHERE n.nspname='f35' AND c.relname='applied_event_ack'
                    AND a.grantee <> c.relowner"
            )->fetchAll(PDO::FETCH_ASSOC);

            if (!is_array($grants) || count($grants) !== 1
                || ($grants[0]['grantee'] ?? null) !== 'f35_ack_worker'
                || ($grants[0]['privilege'] ?? null) !== 'SELECT') {
                return false;
            }

            $roles = $pdo->query(
                "SELECT rolname,rolsuper,rolbypassrls,rolinherit,rolcanlogin
                   FROM pg_catalog.pg_roles WHERE rolname='f35_ack_worker'"
            )->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($roles) || count($roles) !== 1
                || !$this->pgFalse($roles[0]['rolsuper'] ?? null)
                || !$this->pgFalse($roles[0]['rolbypassrls'] ?? null)
                || !$this->pgFalse($roles[0]['rolinherit'] ?? null)
                || !$this->pgTrue($roles[0]['rolcanlogin'] ?? null)) {
                return false;
            }

            $memberLinks = $pdo->query(
                "SELECT count(*) FROM pg_catalog.pg_auth_members m
                   JOIN pg_catalog.pg_roles grant_role ON grant_role.oid=m.roleid
                   JOIN pg_catalog.pg_roles child ON child.oid=m.member
                  WHERE grant_role.rolname='f35_ack_worker'
                     OR child.rolname='f35_ack_worker'"
            )->fetchColumn();
            return in_array($memberLinks, [0,'0'], true);
        } catch (Throwable) {
            return false;
        }
    }

    private function normalize(string $expression): string
    {
        $text = strtolower($expression);
        $text = preg_replace('/[\s()]+/', '', $text) ?? '';
        return str_replace(['::text','::name'], '', $text);
    }

    private function pgTrue(mixed $v): bool
    {
        return in_array($v, [true,1,'1','t','true'], true);
    }

    private function pgFalse(mixed $v): bool
    {
        return in_array($v, [false,0,'0','f','false'], true);
    }
}
