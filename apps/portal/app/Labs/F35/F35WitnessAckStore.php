<?php

declare(strict_types=1);

namespace App\Labs\F35;

use App\Labs\F33\F33SyntheticRevocationEvent;
use App\Labs\F36\F36AckContractInspector;
use PDO;
use Throwable;

/**
 * Strictly synthetic acknowledgement in the same ephemeral witness as F33.
 *
 * null => unavailable or malformed state; false => confirmed unacknowledged;
 * true => event was previously acknowledged with EXACT same payload.
 * Ack is merely a replay checkpoint, never evidence of current primary state.
 */
final readonly class F35WitnessAckStore
{
    public function __construct(private PDO $pdo)
    {
        if (getenv('F35_ENABLE_LAB') !== 'ACK_ONLY_DISPOSABLE_PG18'
            || getenv('F34_ENABLE_LAB') !== 'BOUNDED_REPLAY_ONLY'
            || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
            throw new \LogicException('F35 acknowledgement is only available in synthetic CI.');
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    /** Null means unavailable or integrity failure, never an empty checkpoint. */
    public function isAcknowledged(F33SyntheticRevocationEvent $event): ?bool
    {
        try {
            if (!$this->authorized()
                || $this->pdo->inTransaction()
                || !(new F36AckContractInspector())->intact($this->pdo)) {
                return null;
            }
            $query = $this->pdo->prepare(
                'SELECT organization_id, kind, subject_ref, session_floor,
                        membership_floor, changed_at
                   FROM f35.applied_event_ack
                  WHERE event_id = :event_id'
            );
            $query->execute(['event_id' => $event->eventId]);
            $rows = $query->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($rows) || count($rows) > 1) {
                return null;
            }
            if ($rows === []) {
                return false;
            }
            return $this->matches($event, $rows[0]) ? true : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Called ONLY after primary revocation was independently read back.
     * A stored ack can be replayed but cannot be modified by this role.
     * This order cannot be enforced by the witness function alone.
     */
    public function record(F33SyntheticRevocationEvent $event): ?string
    {
        try {
            if (!$this->authorized()
                || $this->pdo->inTransaction()
                || !(new F36AckContractInspector())->intact($this->pdo)) {
                return null;
            }
            $stmt = $this->pdo->prepare(
                'SELECT f35.ack_applied(
                     :id, :org, :kind, :subject, :session,
                     :membership, :changed, :recorded
                 )'
            );
            $stmt->bindValue(':id', $event->eventId, PDO::PARAM_STR);
            $stmt->bindValue(':org', $event->organization->value, PDO::PARAM_STR);
            $stmt->bindValue(':kind', $event->kind, PDO::PARAM_STR);
            $stmt->bindValue(':subject', $event->subjectRef, PDO::PARAM_STR);
            $stmt->bindValue(':session', $event->sessionFloor, PDO::PARAM_INT);
            $stmt->bindValue(':membership', $event->membershipFloor, PDO::PARAM_INT);
            $stmt->bindValue(':changed', $event->changedAt, PDO::PARAM_INT);
            $stmt->bindValue(':recorded', $event->changedAt, PDO::PARAM_INT);
            $stmt->execute();
            $result = $stmt->fetchColumn();
            return in_array($result, ['recorded', 'replayed'], true) ? $result : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function authorized(): bool
    {
        $identity = $this->pdo->query(
            'SELECT session_user AS login, current_user AS current'
        )->fetch(PDO::FETCH_ASSOC);
        return is_array($identity)
            && $identity['login'] === 'f35_ack_worker'
            && $identity['current'] === 'f35_ack_worker';
    }

    /** @param array<string,mixed> $row */
    private function matches(F33SyntheticRevocationEvent $event, array $row): bool
    {
        return ($row['organization_id'] ?? null) === $event->organization->value
            && ($row['kind'] ?? null) === $event->kind
            && ($row['subject_ref'] ?? null) === $event->subjectRef
            && (string) ($row['session_floor'] ?? '') === (string) $event->sessionFloor
            && (string) ($row['membership_floor'] ?? '') === (string) $event->membershipFloor
            && (string) ($row['changed_at'] ?? '') === (string) $event->changedAt;
    }
}
