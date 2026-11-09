<?php

declare(strict_types=1);

namespace App\Labs\F34;

use App\Labs\F33\F33WitnessFirstRevocationPublisher;
use Throwable;

/**
 * CI-only explicit replay of F33 witness-ledger entries against the primary.
 *
 * No hosted scheduler, durable checkpoint, retries over time, outbox worker,
 * ack of delivery, poison-message handling or production guarantees.
 * The entire bounded set is reprocessed on each explicit invocation and
 * idempotence comes from the F33 event IDs and primary revision guards.
 */
final readonly class F34ReconciliationRunner
{
    public function __construct(
        private F34SyntheticLedgerReader $ledger,
        private F33WitnessFirstRevocationPublisher $publisher,
    ) {
        if (getenv('F34_ENABLE_LAB') !== 'BOUNDED_REPLAY_ONLY') {
            throw new \LogicException('F34 must remain an explicit disposable CI rehearsal.');
        }
    }

    public function runOnce(): F34ReplayOutcome
    {
        try {
            $events = $this->ledger->readBatch();
            if ($events === null) {
                return new F34ReplayOutcome(false, 'ledger_unavailable', 0, 0);
            }
            $discovered = count($events);
            $applied = 0;
            foreach ($events as $event) {
                // Revalidates same event in the witness BEFORE touching
                // primary; failure stops the batch without erasing ledger.
                if (!$this->publisher->finishPrimary($event)) {
                    return new F34ReplayOutcome(
                        false, 'primary_apply_pending', $discovered, $applied,
                    );
                }
                $applied++;
            }

            return new F34ReplayOutcome(true, 'complete', $discovered, $applied);
        } catch (Throwable) {
            return new F34ReplayOutcome(false, 'replay_unavailable', 0, 0);
        }
    }
}
