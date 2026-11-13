<?php
declare(strict_types=1);

namespace App\Infrastructure\Recovery;

/**
 * F374: explicitly separate trust domains for local epoch and remote receipt.
 * This returns DATA only, never a session/HTTP authorization.
 */
final readonly class LiveRecoveryWitnessSourceCandidate implements PortalOnlineWitnessSource
{
    public function __construct(
        private PostgresPortalRecoveryEpochCandidateReader $local,
        private IndependentSignedWitnessTransport $remote,
    ) {}

    public function currentPortalEpoch(): array
    {
        return $this->local->currentEpoch();
    }

    public function latestSignedWitness(): array
    {
        return $this->remote->latestSignedWitness();
    }
}
