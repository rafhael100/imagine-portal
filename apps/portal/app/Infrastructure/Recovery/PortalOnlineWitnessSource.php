<?php
declare(strict_types=1);

namespace App\Infrastructure\Recovery;

/**
 * Trusted server-side *candidate* source, not an HTTP request/body/header.
 *
 * Implementations in a later, separately approved release must fetch each
 * reading from the live Portal database and an independently controlled
 * signed witness, never from the restored backup or a client supplied value.
 */
interface PortalOnlineWitnessSource
{
    /** Ordered keys: organization, instance, generation, session_revision, membership_revision, witnessed_sequence. */
    public function currentPortalEpoch(): array;

    /** Ordered F369 signed witness fields from an independent authoritative source. */
    public function latestSignedWitness(): array;
}
