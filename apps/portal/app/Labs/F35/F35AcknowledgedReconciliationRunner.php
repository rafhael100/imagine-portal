<?php

declare(strict_types=1);

namespace App\Labs\F35;

use App\Labs\F33\F33WitnessFirstRevocationPublisher;
use App\Labs\F34\F34SyntheticLedgerReader;
use Throwable;

/**
 * Explicit F35 lab invocation, never a Laravel job/worker/HTTP handler.
 *
 * Ack is saved ONLY AFTER a tenant-role SELECT of revoked PRIMARY state.
 * An existing ack never bypasses that check: if primary was restored to an
 * old active row, finishPrimary() is retried BEFORE declaring completion.
 *
 * Fail-closed if ledger, ack, primary state, publishing or ack write fail.
 * Not exactly-once delivery, not a trusted signed acknowledgement, and no
 * independent authority outside the runner's two disposable PG clusters.
 */
final readonly class F35AcknowledgedReconciliationRunner
{
    public function __construct(
        private F34SyntheticLedgerReader $ledger,
        private F33WitnessFirstRevocationPublisher $publisher,
        private F35PrimaryStateVerifier $primaryVerifier,
        private F35WitnessAckStore $acks,
    ) {
        if (getenv('F35_ENABLE_LAB') !== 'ACK_ONLY_DISPOSABLE_PG18') {
            throw new \LogicException('F35 cannot run outside its ephemeral CI fixture.');
        }
    }

    public function runOnce(): F35ReplayOutcome
    {
        try {
            $events = $this->ledger->readBatch();
            if ($events === null) {
                return new F35ReplayOutcome(false, 'ledger_unavailable', 0, 0, 0, 0);
            }

            $discovered = count($events);
            $verified = 0;
            $newAcks = 0;
            $repairs = 0;
            foreach ($events as $event) {
                $acked = $this->acks->isAcknowledged($event);
                if ($acked === null) {
                    return new F35ReplayOutcome(
                        false, 'ack_unavailable', $discovered, $verified, $newAcks, $repairs,
                    );
                }

                // Even if already acknowledged, re-check current PRIMARY
                // state: a physical or logical restore can roll it back.
                $applied = $this->primaryVerifier->isApplied($event);
                if ($applied === null) {
                    return new F35ReplayOutcome(
                        false, 'primary_verify_unavailable', $discovered, $verified, $newAcks, $repairs,
                    );
                }

                if (!$applied) {
                    if (!$this->publisher->finishPrimary($event)) {
                        return new F35ReplayOutcome(
                            false, 'primary_apply_pending', $discovered, $verified, $newAcks, $repairs,
                        );
                    }
                    if ($this->primaryVerifier->isApplied($event) !== true) {
                        return new F35ReplayOutcome(
                            false, 'primary_verify_unavailable', $discovered, $verified, $newAcks, $repairs,
                        );
                    }
                    $repairs++;
                }

                if (!$acked) {
                    $result = $this->acks->record($event);
                    if ($result === null) {
                        // Primary may already have committed. Do not claim
                        // completion; next explicit invocation may retry.
                        return new F35ReplayOutcome(
                            false, 'ack_pending', $discovered, $verified, $newAcks, $repairs,
                        );
                    }
                    $newAcks++;
                }
                $verified++;
            }

            return new F35ReplayOutcome(
                true, 'complete', $discovered, $verified, $newAcks, $repairs,
            );
        } catch (Throwable) {
            return new F35ReplayOutcome(false, 'replay_unavailable', 0, 0, 0, 0);
        }
    }
}
