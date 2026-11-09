<?php

declare(strict_types=1);

namespace App\Labs\F35;

use App\Labs\F33\F33SyntheticRevocationEvent;
use PDO;
use Throwable;

/**
 * Read back the synthetic PRIMARY state under the affected tenant's OWN
 * unprivileged SQL role. A previous ACK is never enough to skip a read:
 * a primary pg_restore can put the revoked row back to "active".
 *
 * null means unavailable/untrusted, false means definitely not applied,
 * true means matching revoked state/revision was observed. This is not
 * a transactionally linked proof for an external witness.
 */
final readonly class F35PrimaryStateVerifier
{
    public function __construct(private PDO $tenantA, private PDO $tenantB)
    {
        if (getenv('F35_ENABLE_LAB') !== 'ACK_ONLY_DISPOSABLE_PG18'
            || getenv('F22_ENABLE_LAB') !== 'NON_OPERATIONAL_F22') {
            throw new \LogicException('F35 primary state verification is lab-only.');
        }
        foreach ([$tenantA, $tenantB] as $connection) {
            if ($connection->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
                throw new \InvalidArgumentException('F35 requires PG18 fixture readers.');
            }
            $connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $connection->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        }
    }

    public function isApplied(F33SyntheticRevocationEvent $event): ?bool
    {
        $org = $event->organization->value;
        $db = match ($org) {
            'org-a' => $this->tenantA,
            'org-b' => $this->tenantB,
            default => null,
        };
        if ($db === null || $db->inTransaction()) {
            return null;
        }

        try {
            $db->beginTransaction();
            $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            $db->exec("SET LOCAL statement_timeout = '2000ms'");
            $identity = $db->query(
                'SELECT session_user AS login_role, current_user AS active_role'
            )->fetch(PDO::FETCH_ASSOC);
            $required = $org === 'org-a' ? 'f21_org_a' : 'f21_org_b';
            if (!is_array($identity)
                || $identity['login_role'] !== $required
                || $identity['active_role'] !== $required) {
                $db->rollBack();
                return null;
            }

            $table = $event->kind === 'session'
                ? 'session_fixture' : 'membership_fixture';
            $meta = $db->query(
                "SELECT relrowsecurity AS enabled, relforcerowsecurity AS forced
                   FROM pg_catalog.pg_class
                  WHERE oid='f24.".$table."'::regclass"
            )->fetch(PDO::FETCH_ASSOC);
            if (!is_array($meta)
                || !$this->pgTrue($meta['enabled'] ?? null)
                || !$this->pgTrue($meta['forced'] ?? null)) {
                $db->rollBack();
                return null;
            }

            if ($event->kind === 'session') {
                $sql = 'SELECT mock_state, session_revision AS revision
                          FROM f24.session_fixture
                         WHERE organization_id=:org AND session_id=:subject
                         LIMIT 2';
                $expected = $event->sessionFloor;
            } else {
                $sql = 'SELECT mock_state, membership_revision AS revision,
                               can_view_services AS can_view
                          FROM f24.membership_fixture
                         WHERE organization_id=:org AND actor_id=:subject
                         LIMIT 2';
                $expected = $event->membershipFloor;
            }
            $stmt = $db->prepare($sql);
            $stmt->execute(['org' => $org, 'subject' => $event->subjectRef]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($rows) || count($rows) !== 1) {
                $db->rollBack();
                return null;
            }

            $row = $rows[0];
            $revision = filter_var($row['revision'] ?? null, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1, 'max_range' => 1000000],
            ]);
            if ($revision === false) {
                $db->rollBack();
                return null;
            }
            $verified = $row['mock_state'] === 'revoked'
                && $revision >= $expected
                && ($event->kind !== 'membership'
                    || $this->pgFalse($row['can_view'] ?? null));
            $db->commit();
            return $verified;
        } catch (Throwable) {
            try {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
            } catch (Throwable) {
                // Fail closed; do not escalate to the admin fixture role.
            }
            return null;
        }
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
