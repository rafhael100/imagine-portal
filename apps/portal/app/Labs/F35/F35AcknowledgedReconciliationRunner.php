<?php

declare(strict_types=1);

namespace App\Labs\F35;

use App\Labs\F33\F33WitnessFirstRevocationPublisher;
use App\Labs\F34\F34SyntheticLedgerReader;
use App\Labs\F34\F34ReplayOutcome;
use Throwable;

/**
 * Explicit CI run, not a scheduled worker. Processes <=20 UNACKED events,
 * records an ack only AFTER the primary state is observed under a tenant SQL
 * reader role; never silently deletes the immutable witness ledger.
 */
final readonly class F35AcknowledgedReconciliationRunner
{
    public function __construct(
        private F34SyntheticLedgerReader $ledger,
        private F33WitnessFirstRevocationPublisher $publisher,
        private F35PrimaryStateVerifier $verifier,
        private F35WitnessAcknowledgementWriter $ack,
    ) {
        if (getenv('F35_ENABLE_LAB') !== 'ACKNOWLEDGEMENTS_CI_ONLY') {
            throw new \LogicException('F35 is not an operational reconciliation worker.');
        }
    }

    public function runOnce(): F34ReplayOutcome
    {
        try {
            $events = $this->ledger->readBatch(onlyUnacknowledged: true);
            if ($events === null) {
                return new F34ReplayOutcome(false,'ledger_unavailable',0,0);
            }

            $applied = 0;
            $discovered = count($events);
            foreach ($events as $event) {
                if (!$this->publisher->finishPrimary($event)) {
                    return new F34ReplayOutcome(
                        false,'primary_apply_pending',$discovered,$applied,
                    );
                }

                $confirmed = $this->verifier->observeRevoked($event);
                if ($confirmed === null) {
                    return new F34ReplayOutcome(
                        false,'primary_unconfirmed',$discovered,$applied,
                    );
                }

                if (!$this->ack->confirm($event,$confirmed)) {
                    return new F34ReplayOutcome(
                        false,'ack_pending',$discovered,$applied,
                    );
                }
                $applied++;
            }
            return new F34ReplayOutcome(true,'complete',$discovered,$applied);
        } catch (Throwable) {
            return new F34ReplayOutcome(false,'reconciliation_unavailable',0,0);
        }
    }
}
