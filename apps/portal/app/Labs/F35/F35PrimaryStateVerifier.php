<?php

declare(strict_types=1);

namespace App\Labs\F35;

use App\Labs\F33\F33SyntheticRevocationEvent;
use PDO;
use Throwable;

/**
 * Reads one tenant-bound fake primary SQL identity to confirm the event
 * is actually applied. Not an independent attestation of the primary DB,
 * a production IAM principal, or a protection against a forged PHP caller.
 */
final readonly class F35PrimaryStateVerifier
{
    public function __construct(
        private PDO $organizationA,
        private PDO $organizationB,
    ) {
        if (getenv('F22_ENABLE_LAB') !== 'NON_OPERATIONAL_F22'
            || getenv('F35_ENABLE_LAB') !== 'ACKNOWLEDGEMENTS_CI_ONLY') {
            throw new \LogicException('F35 is limited to disposable SQL fixtures.');
        }
        foreach ([$organizationA, $organizationB] as $pdo) {
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
                throw new \InvalidArgumentException('F35 expects disposable PostgreSQL sessions.');
            }
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        }
    }

    /**
     * Returns a confirmed applied revision, or null on any mismatch/failure.
     * The actor cannot select tenant by passing a client-controlled role.
     */
    public function observeRevoked(F33SyntheticRevocationEvent $event): ?int
    {
        $role = match ($event->organization->value) {
            'org-a' => 'f21_org_a',
            'org-b' => 'f21_org_b',
        };
        $pdo = $event->organization->value === 'org-a'
            ? $this->organizationA : $this->organizationB;
        if ($pdo->inTransaction()) {
            return null;
        }
        try {
            $pdo->beginTransaction();
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            $pdo->exec("SET LOCAL statement_timeout='2000ms'");

            $dbRole = $pdo->query(
                'SELECT session_user AS login_role,current_user AS active_role'
            )->fetch(PDO::FETCH_ASSOC);
            if (!is_array($dbRole)
                || $dbRole['login_role'] !== $role
                || $dbRole['active_role'] !== $role) {
                $pdo->rollBack();
                return null;
            }

            // An ACK must not be issued if the observation side has lost
            // its FORCE RLS or tenant SQL role isolation. This remains
            // negative drift detection of fake fixtures, not IAM proof.
            $security = $pdo->query(
                "SELECT c.relname AS object_name,
                        c.relrowsecurity AS rls_on,
                        c.relforcerowsecurity AS rls_forced,
                        (SELECT (NOT r.rolsuper AND NOT r.rolbypassrls
                                      AND NOT r.rolinherit AND r.rolcanlogin)
                          FROM pg_catalog.pg_roles r WHERE r.rolname=current_user)
                          AS restricted_role
                   FROM pg_catalog.pg_class c
                   JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace
                  WHERE n.nspname='f24'
                    AND c.relname IN ('session_fixture','membership_fixture')
                    AND c.relkind='r'"
            )->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($security) || count($security) !== 2) {
                $pdo->rollBack();
                return null;
            }
            foreach ($security as $relation) {
                if (!in_array($relation['object_name'] ?? null,
                      ['session_fixture','membership_fixture'],true)
                    || !$this->pgTrue($relation['rls_on'] ?? null)
                    || !$this->pgTrue($relation['rls_forced'] ?? null)
                    || !$this->pgTrue($relation['restricted_role'] ?? null)) {
                    $pdo->rollBack();
                    return null;
                }
            }

            $table = $event->kind === 'session'
                ? 'session_fixture' : 'membership_fixture';
            $column = $event->kind === 'session' ? 'session_id' : 'actor_id';
            $revision = $event->kind === 'session'
                ? 'session_revision' : 'membership_revision';
            $max = $event->kind === 'session'
                ? $event->sessionFloor : $event->membershipFloor;

            // Exactly two fixed table/column names; never interpolate
            // client-controlled identifiers or SQL.
            $sql = 'SELECT '.$revision.' AS rev,mock_state AS state
                      FROM f24.'.$table.'
                     WHERE organization_id=:org AND '.$column.'=:subject
                     LIMIT 2';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                'org' => $event->organization->value,
                'subject' => $event->subjectRef,
            ]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) !== 1
                || $rows[0]['state'] !== 'revoked'
                || !preg_match('/\A[0-9]{1,7}\z/D', (string) ($rows[0]['rev'] ?? ''))) {
                $pdo->rollBack();
                return null;
            }
            $observed = (int) $rows[0]['rev'];
            if ($observed < $max || $observed > 1000000) {
                $pdo->rollBack();
                return null;
            }
            if ($event->kind === 'membership') {
                // A revoked membership must have lost the view permission.
                $check = $pdo->prepare(
                    'SELECT can_view_services FROM f24.membership_fixture
                      WHERE organization_id=:org AND actor_id=:subject'
                );
                $check->execute([
                    'org' => $event->organization->value,
                    'subject' => $event->subjectRef,
                ]);
                $val = $check->fetchColumn();
                if (!in_array($val,[false,'f','false',0,'0'],true)) {
                    $pdo->rollBack();
                    return null;
                }
            }
            $pdo->commit();
            return $observed;
        } catch (Throwable) {
            try {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } catch (Throwable) {
                // Lost connection remains an unconfirmed state.
            }
            return null;
        }
    }

    private function pgTrue(mixed $value): bool
    {
        return in_array($value,[true,'t','true',1,'1'],true);
    }
}
