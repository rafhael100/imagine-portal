<?php

declare(strict_types=1);

namespace App\Labs\F35;

use App\Labs\F33\F33SyntheticRevocationEvent;
use PDO;
use Throwable;

/**
 * Lab-only constrained witness ACK writer. This records a *claim* of
 * primary observation. The SQL procedure cannot itself authenticate
 * a remote database's state or the PHP caller: NEVER use as production
 * external attestation.
 */
final readonly class F35WitnessAcknowledgementWriter
{
    public function __construct(private PDO $pdo)
    {
        if (getenv('F22_ENABLE_LAB') !== 'NON_OPERATIONAL_F22'
            || getenv('F31_ENABLE_LAB') !== 'INDEPENDENT_DISPOSABLE_WITNESS'
            || getenv('F35_ENABLE_LAB') !== 'ACKNOWLEDGEMENTS_CI_ONLY'
            || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
            throw new \LogicException('F35 ACK is restricted to ephemeral witness fixtures.');
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    public function confirm(F33SyntheticRevocationEvent $event, int $observedRevision): bool
    {
        if ($this->pdo->inTransaction()
            || $observedRevision < 1 || $observedRevision > 1000000) {
            return false;
        }
        try {
            $role = $this->pdo->query(
                'SELECT session_user AS login_role,current_user AS active_role'
            )->fetch(PDO::FETCH_ASSOC);
            if (!is_array($role)
                || $role['login_role'] !== 'f35_acknowledgement_writer'
                || $role['active_role'] !== 'f35_acknowledgement_writer') {
                return false;
            }
            $statement = $this->pdo->prepare(
                'SELECT f35.confirm_observed_primary(
                    :event_id,:org,:kind,:subject,:rev,:observed_at
                 ) AS ack_result'
            );
            $statement->bindValue(':event_id',$event->eventId,PDO::PARAM_STR);
            $statement->bindValue(':org',$event->organization->value,PDO::PARAM_STR);
            $statement->bindValue(':kind',$event->kind,PDO::PARAM_STR);
            $statement->bindValue(':subject',$event->subjectRef,PDO::PARAM_STR);
            $statement->bindValue(':rev',$observedRevision,PDO::PARAM_INT);
            $statement->bindValue(':observed_at',1800000040,PDO::PARAM_INT);
            $statement->execute();
            return in_array($statement->fetchColumn(),['recorded','already_recorded'],true);
        } catch (Throwable) {
            return false;
        }
    }
}
