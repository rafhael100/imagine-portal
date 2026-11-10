<?php

declare(strict_types=1);

namespace App\Labs\F33;

use PDO;
use Throwable;

/**
 * A WITNESS-FIRST laboratory rehearsal, not a production dispatcher.
 *
 * IMPORTANT ORDER:
 *   1. Commit the monotonically increasing floor + event ID to the
 *      witness in its own DB transaction.
 *   2. Only after step 1 is confirmed, write the revocation to primary.
 *
 * If the process dies between 1 and 2, the already committed witness
 * rejects old proof revisions; a retry with the exact event ID is safe.
 * This does NOT guarantee that every real revocation enters this protocol,
 * nor solve coordinated rollback, network partitions, downstream caches
 * or revocation linearizability.
 */
final readonly class F33WitnessFirstRevocationPublisher
{
    public function __construct(
        private PDO $witnessPublisher,
        private PDO $primaryFixtureWriter,
    ) {
        if (getenv('F22_ENABLE_LAB') !== 'NON_OPERATIONAL_F22'
            || getenv('F31_ENABLE_LAB') !== 'INDEPENDENT_DISPOSABLE_WITNESS'
            || getenv('F33_ENABLE_LAB') !== 'WITNESS_FIRST_SYNTHETIC_ONLY') {
            throw new \LogicException('F33 only runs in its two-container disposable fixture.');
        }
        foreach ([$witnessPublisher, $primaryFixtureWriter] as $pdo) {
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
                throw new \InvalidArgumentException('F33 requires ephemeral PostgreSQL connections.');
            }
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        }
    }

    /**
     * Commit witness intent FIRST. Return null if the writer identity,
     * SQL procedure, permissions, or availability fail.
     *
     * The returned 'published' or 'replayed' is NOT a complete revocation.
     */
    public function publishWitness(F33SyntheticRevocationEvent $event): ?string
    {
        if ($this->witnessPublisher->inTransaction()) {
            return null;
        }
        try {
            if (!$this->isExpectedIdentity($this->witnessPublisher, 'f33_revocation_publisher')) {
                return null;
            }
            $stmt = $this->witnessPublisher->prepare(
                'SELECT f33.publish_revocation(
                    :event_id, :org, :kind, :subject,
                    :session_floor, :membership_floor, :changed_at
                 ) AS result'
            );
            $this->bindEvent($stmt, $event);
            $stmt->execute();
            $result = $stmt->fetchColumn();
            return in_array($result, ['published', 'replayed'], true) ? $result : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Retry safe: re-confirm witness event FIRST, THEN perform primary DML
     * in a separate transaction. If witness publishing fails, primary is
     * NOT mutated, and callers must not report a completed revocation.
     *
     * Uses the admin identity only because prior F24 fixtures have no
     * operational writer API. This does not authorize admin access in
     * any non-test environment.
     */
    public function finishPrimary(F33SyntheticRevocationEvent $event): bool
    {
        if ($this->primaryFixtureWriter->inTransaction()
            || $this->publishWitness($event) === null) {
            return false;
        }

        try {
            if (!$this->isExpectedIdentity($this->primaryFixtureWriter, 'postgres')) {
                return false;
            }
            $this->primaryFixtureWriter->beginTransaction();
            $this->primaryFixtureWriter->exec("SET LOCAL statement_timeout = '2000ms'");
            $this->primaryFixtureWriter->exec("SET LOCAL lock_timeout = '2000ms'");

            if ($event->kind === 'session') {
                $sql = 'UPDATE f24.session_fixture
                           SET mock_state = :state, session_revision = :new_revision
                         WHERE organization_id = :org AND session_id = :subject
                           AND session_revision <= :max_revision
                         RETURNING session_revision';
                $revision = $event->sessionFloor;
            } else {
                $sql = 'UPDATE f24.membership_fixture
                           SET mock_state = :state,
                               membership_revision = :new_revision,
                               can_view_services = false
                         WHERE organization_id = :org AND actor_id = :subject
                           AND membership_revision <= :max_revision
                         RETURNING membership_revision';
                $revision = $event->membershipFloor;
            }

            $stmt = $this->primaryFixtureWriter->prepare($sql);
            $stmt->bindValue(':state', 'revoked', PDO::PARAM_STR);
            $stmt->bindValue(':new_revision', $revision, PDO::PARAM_INT);
            $stmt->bindValue(':max_revision', $revision, PDO::PARAM_INT);
            $stmt->bindValue(':org', $event->organization->value, PDO::PARAM_STR);
            $stmt->bindValue(':subject', $event->subjectRef, PDO::PARAM_STR);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) !== 1
                || (string) $rows[0][
                    $event->kind === 'session' ? 'session_revision' : 'membership_revision'
                ] !== (string) $revision) {
                $this->primaryFixtureWriter->rollBack();
                return false;
            }
            $this->primaryFixtureWriter->commit();
            return true;
        } catch (Throwable) {
            try {
                if ($this->primaryFixtureWriter->inTransaction()) {
                    $this->primaryFixtureWriter->rollBack();
                }
            } catch (Throwable) {
                // Preserve fail-closed result; never retry with other roles.
            }
            return false;
        }
    }

    private function isExpectedIdentity(PDO $pdo, string $expected): bool
    {
        $row = $pdo->query(
            'SELECT session_user AS login_identity, current_user AS active_identity'
        )->fetch(PDO::FETCH_ASSOC);

        return is_array($row)
            && $row['login_identity'] === $expected
            && $row['active_identity'] === $expected;
    }

    private function bindEvent(\PDOStatement $stmt, F33SyntheticRevocationEvent $event): void
    {
        $stmt->bindValue(':event_id', $event->eventId, PDO::PARAM_STR);
        $stmt->bindValue(':org', $event->organization->value, PDO::PARAM_STR);
        $stmt->bindValue(':kind', $event->kind, PDO::PARAM_STR);
        $stmt->bindValue(':subject', $event->subjectRef, PDO::PARAM_STR);
        $stmt->bindValue(':session_floor', $event->sessionFloor, PDO::PARAM_INT);
        $stmt->bindValue(':membership_floor', $event->membershipFloor, PDO::PARAM_INT);
        $stmt->bindValue(':changed_at', $event->changedAt, PDO::PARAM_INT);
    }
}
