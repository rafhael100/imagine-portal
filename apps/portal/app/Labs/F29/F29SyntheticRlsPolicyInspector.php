<?php

declare(strict_types=1);

namespace App\Labs\F29;

use PDO;
use Throwable;

/**
 * PORTAL-F29: exact negative drift check for two disposable SQL lab schemas.
 *
 * This does NOT verify real authorization, independent policy attestation,
 * SQL ownership, external services, JWTs or production RLS. A DB owner who
 * also controls application code can defeat this comparison.
 *
 * The policy predicates below are intentionally hard-coded for the F21/F24
 * fixtures. No user-supplied identifier or SQL is interpolated.
 */
final readonly class F29SyntheticRlsPolicyInspector
{
    private const EXPECTED_POLICIES = [
        'f21.service.f21_service_a' => "organization_id='org-a'andsession_user='f21_org_a'",
        'f21.service.f21_service_b' => "organization_id='org-b'andsession_user='f21_org_b'",
        'f21.request.f21_request_a' => "organization_id='org-a'andsession_user='f21_org_a'",
        'f21.request.f21_request_b' => "organization_id='org-b'andsession_user='f21_org_b'",
        'f21.contract.f21_contract_a' => "organization_id='org-a'andsession_user='f21_org_a'",
        'f21.contract.f21_contract_b' => "organization_id='org-b'andsession_user='f21_org_b'",
        'f24.session_fixture.f24_session_a' => "organization_id='org-a'andsession_user='f21_org_a'",
        'f24.session_fixture.f24_session_b' => "organization_id='org-b'andsession_user='f21_org_b'",
        'f24.membership_fixture.f24_member_a' => "organization_id='org-a'andsession_user='f21_org_a'",
        'f24.membership_fixture.f24_member_b' => "organization_id='org-b'andsession_user='f21_org_b'",
    ];

    private const EXPECTED_RELATIONS = [
        'f21.service', 'f21.request', 'f21.contract', 'f21.service_summary',
        'f24.session_fixture', 'f24.membership_fixture',
    ];

    public function intact(PDO $pdo): bool
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql'
            || !$pdo->inTransaction()) {
            return false;
        }

        try {
            // Examine the actual policy catalog; pg_policies summaries alone
            // do not expose every internal policy flag/role OID.
            $policies = $pdo->query(
                "SELECT n.nspname AS schema_name, c.relname AS table_name,
                        p.polname AS policy_name, p.polcmd AS policy_command,
                        p.polpermissive AS is_permissive,
                        p.polwithcheck IS NULL AS no_write_expression,
                        pg_catalog.pg_get_expr(p.polqual,p.polrelid) AS predicate,
                        (SELECT string_agg(COALESCE(r.rolname,'PUBLIC'), ',' ORDER BY role_id)
                           FROM unnest(p.polroles) AS oid_list(role_id)
                           LEFT JOIN pg_catalog.pg_roles r ON r.oid=oid_list.role_id
                        ) AS role_names
                   FROM pg_catalog.pg_policy p
                   JOIN pg_catalog.pg_class c ON c.oid=p.polrelid
                   JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace
                  WHERE n.nspname IN ('f21','f24')"
            )->fetchAll(PDO::FETCH_ASSOC);

            if (!is_array($policies) || count($policies) !== count(self::EXPECTED_POLICIES)) {
                return false;
            }

            $remaining = self::EXPECTED_POLICIES;
            foreach ($policies as $policy) {
                if (!is_array($policy)) {
                    return false;
                }
                $key = ($policy['schema_name'] ?? '').'.'
                    .($policy['table_name'] ?? '').'.'
                    .($policy['policy_name'] ?? '');

                if (!isset($remaining[$key])
                    || $policy['policy_command'] !== 'r'
                    || !$this->pgTrue($policy['is_permissive'] ?? null)
                    || !$this->pgTrue($policy['no_write_expression'] ?? null)
                    || !is_string($policy['predicate'] ?? null)
                    || $this->normalizePredicate($policy['predicate']) !== $remaining[$key]) {
                    return false;
                }

                $expectedRole = str_ends_with($key, '_a') ? 'f21_org_a' : 'f21_org_b';
                if ($policy['role_names'] !== $expectedRole) {
                    return false;
                }
                unset($remaining[$key]);
            }
            if ($remaining !== []) {
                return false;
            }

            // Reject privileges to PUBLIC, unexpected roles, and any WRITE
            // grant even if all RLS policies still appear intact.
            $grants = $pdo->query(
                "SELECT n.nspname AS schema_name, c.relname AS relation_name,
                        c.relkind AS relation_kind,
                        COALESCE(r.rolname,'PUBLIC') AS grantee_name,
                        a.privilege_type AS privilege
                   FROM pg_catalog.pg_class c
                   JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace
                   CROSS JOIN LATERAL
                     pg_catalog.aclexplode(COALESCE(c.relacl, pg_catalog.acldefault('r', c.relowner))) a
                   LEFT JOIN pg_catalog.pg_roles r ON r.oid=a.grantee
                  WHERE n.nspname IN ('f21','f24')
                    AND c.relkind IN ('r','v')
                    AND a.grantee <> c.relowner"
            )->fetchAll(PDO::FETCH_ASSOC);

            if (!is_array($grants) || count($grants) !== count(self::EXPECTED_RELATIONS) * 2) {
                return false;
            }

            $expectedGrants = [];
            foreach (self::EXPECTED_RELATIONS as $relation) {
                foreach (['f21_org_a','f21_org_b'] as $role) {
                    $expectedGrants[$relation.'.'.$role] = true;
                }
            }

            foreach ($grants as $grant) {
                if (!is_array($grant)) {
                    return false;
                }
                $key = ($grant['schema_name'] ?? '').'.'
                    .($grant['relation_name'] ?? '').'.'
                    .($grant['grantee_name'] ?? '');
                if (!isset($expectedGrants[$key])
                    || $grant['privilege'] !== 'SELECT'
                    || !in_array($grant['relation_kind'], ['r','v'], true)) {
                    return false;
                }
                unset($expectedGrants[$key]);
            }
            if ($expectedGrants !== []) {
                return false;
            }

            // Prevent implicit cross-role membership and role escalation.
            $roles = $pdo->query(
                "SELECT rolname, rolsuper, rolbypassrls, rolinherit, rolcanlogin
                   FROM pg_catalog.pg_roles
                  WHERE rolname IN ('f21_org_a','f21_org_b')"
            )->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($roles) || count($roles) !== 2) {
                return false;
            }
            foreach ($roles as $role) {
                if (!in_array($role['rolname'], ['f21_org_a','f21_org_b'], true)
                    || !$this->pgFalse($role['rolsuper'])
                    || !$this->pgFalse($role['rolbypassrls'])
                    || !$this->pgFalse($role['rolinherit'])
                    || !$this->pgTrue($role['rolcanlogin'])) {
                    return false;
                }
            }
            $roleLinks = $pdo->query(
                "SELECT count(*) AS links
                   FROM pg_catalog.pg_auth_members m
                   JOIN pg_catalog.pg_roles parent ON parent.oid=m.roleid
                   JOIN pg_catalog.pg_roles child ON child.oid=m.member
                  WHERE parent.rolname IN ('f21_org_a','f21_org_b')
                     OR child.rolname IN ('f21_org_a','f21_org_b')"
            )->fetchColumn();

            return in_array($roleLinks, [0, '0'], true);
        } catch (Throwable) {
            return false;
        }
    }

    private function normalizePredicate(string $expression): string
    {
        // The template contains only fixed DEMO labels and simple equality.
        // No SQL expressions are built from this normalized text.
        $text = strtolower($expression);
        $text = preg_replace('/[\s()]+/', '', $text) ?? '';
        return str_replace(['::text', '::name'], '', $text);
    }

    private function pgTrue(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 't', 'true'], true);
    }

    private function pgFalse(mixed $value): bool
    {
        return in_array($value, [false, 0, '0', 'f', 'false'], true);
    }
}
