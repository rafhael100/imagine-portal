<?php

declare(strict_types=1);

namespace App\Domain\SitesIntegration;

use InvalidArgumentException;

/** Candidate-only atomic synthetic signing secret and generation. */
final readonly class CandidatePortalSitesSigningMaterial
{
    public function __construct(
        public string $issuer,
        public string $audience,
        public string $secret,
        public int $keyEpoch,
    ) {
        if ($issuer !== 'imagine-portal' || $audience !== 'imagine-sites' ||
            strlen($secret) < 32 || $keyEpoch < 1) {
            throw new InvalidArgumentException('Untrusted synthetic signing material.');
        }
    }
}
