<?php

declare(strict_types=1);

namespace App\Labs\F36;

use App\Labs\F33\F33WitnessFirstRevocationPublisher;
use App\Labs\F34\F34ReplayOutcome;
use App\Labs\F34\F34SyntheticLedgerReader;
use App\Labs\F35\F35PrimaryStateVerifier;
use App\Labs\F35\F35WitnessAcknowledgementWriter;
use Throwable;

/**
 * CI-only bounded pagination over UNACKNOWLEDGED witness publications.
 *
 * Never uses OFFSET (which would skip records as ACKs remove rows from
 * pending selection). Every page is <= 20; after each ACK, the next
 * read returns the first pending row. Up to 100 events per invocation.
 * This is NOT a deployed job, queue or authorization mechanism.
 */
final readonly class F36BoundedPagedAcknowledgementRunner
{
    private const MAX_BATCHES = 5;

    public function __construct(
        private F34SyntheticLedgerReader $ledger,
        private F33WitnessFirstRevocationPublisher $publisher,
        private F35PrimaryStateVerifier $verifier,
        private F35WitnessAcknowledgementWriter $ack,
    ) {
        if (getenv('F22_ENABLE_LAB') !== 'NON_OPERATIONAL_F22'
            || getenv('F35_ENABLE_LAB') !== 'ACKNOWLEDGEMENTS_CI_ONLY'
            || getenv('F36_ENABLE_LAB') !== 'PAGED_REPLAY_ONLY') {
            throw new \LogicException('F36 runs only inside synthetic PHP/PG18 CI.');
        }
    }

    public function runOnce(): F34ReplayOutcome
    {
        $discovered = 0;
        $confirmed = 0;

        try {
            for ($round = 0; $round < self::MAX_BATCHES; $round++) {
                $events = $this->ledger->readBatch(
                    onlyUnacknowledged: true,
                    pageMode: true,
                );
                if ($events === null) {
                    return new F34ReplayOutcome(
                        false, 'ledger_unavailable', $discovered, $confirmed,
                    );
                }
                if ($events === []) {
                    return new F34ReplayOutcome(
                        true, 'complete', $discovered, $confirmed,
                    );
                }
                $discovered += count($events);
                foreach ($events as $event) {
                    // Witness event must still be valid BEFORE primary DML.
                    if (!$this->publisher->finishPrimary($event)) {
                        return new F34ReplayOutcome(
                            false, 'primary_apply_pending', $discovered, $confirmed,
                        );
                    }

                    $observed = $this->verifier->observeRevoked($event);
                    if ($observed === null) {
                        return new F34ReplayOutcome(
                            false, 'primary_unconfirmed', $discovered, $confirmed,
                        );
                    }
                    if (!$this->ack->confirm($event, $observed)) {
                        return new F34ReplayOutcome(
                            false, 'ack_pending', $discovered, $confirmed,
                        );
                    }
                    $confirmed++;
                }
            }

            // Explicitly check whether bounded processing exhausted the
            // pending queue. Do NOT claim success when work remains.
            $remaining = $this->ledger->readBatch(
                onlyUnacknowledged: true,
                pageMode: true,
            );
            if ($remaining === null) {
                return new F34ReplayOutcome(
                    false, 'ledger_unavailable', $discovered, $confirmed,
                );
            }
            if ($remaining !== []) {
                return new F34ReplayOutcome(
                    false, 'bounded_backpressure',
                    $discovered + count($remaining), $confirmed,
                );
            }
            return new F34ReplayOutcome(
                true, 'complete', $discovered, $confirmed,
            );
        } catch (Throwable) {
            return new F34ReplayOutcome(
                false, 'reconciliation_unavailable', $discovered, $confirmed,
            );
        }
    }
}
