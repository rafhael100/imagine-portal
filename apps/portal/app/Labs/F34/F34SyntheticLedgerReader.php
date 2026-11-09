<?php

declare(strict_types=1);

namespace App\Labs\F34;

use App\Domain\Organizations\OrganizationId;
use App\Labs\F33\F33SyntheticRevocationEvent;
use PDO;
use Throwable;

/**
 * F34 CI-only read-only ledger scanner, never a production queue consumer.
 * A null return means FAIL/UNAVAILABLE, [] means confirmed empty.
 */
final readonly class F34SyntheticLedgerReader
{
    private const MAX_EVENTS = 20;

    public function __construct(private PDO $pdo)
    {
        if (getenv('F22_ENABLE_LAB') !== 'NON_OPERATIONAL_F22'
            || getenv('F31_ENABLE_LAB') !== 'INDEPENDENT_DISPOSABLE_WITNESS'
            || getenv('F33_ENABLE_LAB') !== 'WITNESS_FIRST_SYNTHETIC_ONLY'
            || getenv('F34_ENABLE_LAB') !== 'BOUNDED_REPLAY_ONLY'
            || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
            throw new \LogicException('F34 only accepts a disposable read-only witness ledger.');
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    /** @return list<F33SyntheticRevocationEvent>|null */
    public function readBatch(bool $onlyUnacknowledged = false): ?array
    {
        if ($this->pdo->inTransaction()) {
            return null;
        }

        try {
            $this->pdo->beginTransaction();
            $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            $this->pdo->exec("SET LOCAL statement_timeout = '2000ms'");

            $auth = $this->pdo->query(
                "SELECT session_user AS login_role, current_user AS active_role,
                        (SELECT NOT rolsuper AND NOT rolbypassrls
                                AND NOT rolinherit AND rolcanlogin
                           FROM pg_catalog.pg_roles WHERE rolname=current_user) AS restricted"
            )->fetch(PDO::FETCH_ASSOC);
            if (!is_array($auth)
                || $auth['login_role'] !== 'f34_reconciliation_reader'
                || $auth['active_role'] !== 'f34_reconciliation_reader'
                || !$this->pgTrue($auth['restricted'] ?? null)) {
                $this->pdo->rollBack();
                return null;
            }

            $meta = $this->pdo->query(
                "SELECT c.relrowsecurity AS rls_on,
                        c.relforcerowsecurity AS rls_force,
                        (SELECT count(*)::int FROM pg_catalog.pg_policy p
                          WHERE p.polrelid=c.oid AND p.polname='f34_reader_ledger'
                            AND p.polcmd='r') AS policy_count,
                        (SELECT count(*)::int FROM pg_catalog.pg_policy p
                          WHERE p.polrelid=c.oid) AS total_policies
                   FROM pg_catalog.pg_class c
                   JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace
                  WHERE n.nspname='f33'
                    AND c.relname='publication_ledger' AND c.relkind='r'"
            )->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($meta) || count($meta) !== 1
                || !$this->pgTrue($meta[0]['rls_on'] ?? null)
                || !$this->pgTrue($meta[0]['rls_force'] ?? null)
                || !in_array($meta[0]['policy_count'] ?? null, [1,'1'], true)
                || !in_array($meta[0]['total_policies'] ?? null, [1,'1'], true)) {
                $this->pdo->rollBack();
                return null;
            }

            if ($onlyUnacknowledged) {
                // F35 receipts must be protected with the same restricted
                // ledger-reader role. Do not silently interpret missing
                // receipt privileges as "nothing to process".
                $ackCatalog = $this->pdo->query(
                    "SELECT c.relrowsecurity AS rls_on,
                            c.relforcerowsecurity AS rls_force,
                            (SELECT count(*)::int FROM pg_catalog.pg_policy p
                              WHERE p.polrelid=c.oid
                                AND p.polname='f35_receipts_reader'
                                AND p.polcmd='r') AS policy_count,
                            (SELECT count(*)::int FROM pg_catalog.pg_policy p
                              WHERE p.polrelid=c.oid) AS policy_total
                       FROM pg_catalog.pg_class c
                       JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace
                      WHERE n.nspname='f35'
                        AND c.relname='applied_receipt' AND c.relkind='r'"
                )->fetchAll(PDO::FETCH_ASSOC);
                if (!is_array($ackCatalog) || count($ackCatalog) !== 1
                    || !$this->pgTrue($ackCatalog[0]['rls_on'] ?? null)
                    || !$this->pgTrue($ackCatalog[0]['rls_force'] ?? null)
                    || !in_array($ackCatalog[0]['policy_count'] ?? null, [1,'1'], true)
                    || !in_array($ackCatalog[0]['policy_total'] ?? null, [1,'1'], true)) {
                    $this->pdo->rollBack();
                    return null;
                }
                $sql = "SELECT e.event_id, e.organization_id, e.kind,
                               e.subject_ref, e.session_floor,
                               e.membership_floor, e.changed_at,
                               e.sequence_number
                          FROM f33.publication_ledger e
                          LEFT JOIN f35.applied_receipt a
                            ON a.event_id=e.event_id
                         WHERE a.event_id IS NULL
                         ORDER BY e.organization_id,e.sequence_number,e.event_id
                         LIMIT 21";
            } else {
                $sql = "SELECT event_id, organization_id, kind, subject_ref,
                               session_floor, membership_floor, changed_at, sequence_number
                          FROM f33.publication_ledger
                         ORDER BY organization_id, sequence_number, event_id
                         LIMIT 21";
            }
            $rows = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($rows) || count($rows) > self::MAX_EVENTS) {
                $this->pdo->rollBack();
                return null;
            }

            $events = [];
            $lastSequenceByOrg = [];
            foreach ($rows as $row) {
                if (!is_array($row)
                    || !is_string($row['organization_id'] ?? null)
                    || !is_string($row['event_id'] ?? null)
                    || !is_string($row['kind'] ?? null)
                    || !is_string($row['subject_ref'] ?? null)) {
                    $this->pdo->rollBack();
                    return null;
                }
                $seq = $this->intInRange($row['sequence_number'] ?? null, 100000000);
                $session = $this->intInRange($row['session_floor'] ?? null, 1000000);
                $member = $this->intInRange($row['membership_floor'] ?? null, 1000000);
                $changedAt = $this->intInRange($row['changed_at'] ?? null, 1800003600);
                if ($seq === null || $session === null
                    || $member === null || $changedAt === null
                    || ($lastSequenceByOrg[$row['organization_id']] ?? 0) >= $seq) {
                    $this->pdo->rollBack();
                    return null;
                }
                $lastSequenceByOrg[$row['organization_id']] = $seq;
                $events[] = new F33SyntheticRevocationEvent(
                    new OrganizationId($row['organization_id']),
                    $row['event_id'], $row['kind'], $row['subject_ref'],
                    $session, $member, $changedAt,
                );
            }

            $this->pdo->commit();
            return $events;
        } catch (Throwable) {
            try {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
            } catch (Throwable) {
                // Fail closed: do not confuse disconnected with empty ledger.
            }
            return null;
        }
    }

    private function intInRange(mixed $raw, int $max): ?int
    {
        if (!is_int($raw)
            && !(is_string($raw) && preg_match('/\A[0-9]{1,10}\z/D', $raw) === 1)) {
            return null;
        }
        $value = filter_var($raw, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => $max],
        ]);
        return $value === false ? null : (int) $value;
    }

    private function pgTrue(mixed $value): bool
    {
        return in_array($value, [true,'t','true',1,'1'], true);
    }
}
