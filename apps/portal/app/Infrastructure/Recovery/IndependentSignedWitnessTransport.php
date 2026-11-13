<?php
declare(strict_types=1);

namespace App\Infrastructure\Recovery;

/**
 * Future independent, authenticated publisher/reader contract. There is NO
 * operational implementation: signing must take place off the Portal host,
 * with pinned key, online no-cache freshness and fail-closed transport.
 */
interface IndependentSignedWitnessTransport
{
    public function latestSignedWitness(): array;
}
