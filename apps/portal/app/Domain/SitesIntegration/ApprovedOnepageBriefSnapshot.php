<?php

declare(strict_types=1);

namespace App\Domain\SitesIntegration;

use InvalidArgumentException;

/**
 * A value projected by an independent, authorized Portal briefing reader.
 * Merely constructing it is not authority and must never be an API payload.
 */
final readonly class ApprovedOnepageBriefSnapshot
{
    /** @param array<string, int|string> $selection Explicit 7-field Sites selector. */
    public function __construct(
        public SiteContractSnapshot $contract,
        public array $selection,
        public string $sourceBytes,
        public string $sourceSha256,
        public string $approvalRef,
        public int $approvalEpoch,
    ) {
        if ($contract->productCode !== 'onepage' ||
            preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $approvalRef) !== 1 ||
            $approvalEpoch < 1 ||
            preg_match('/\A[a-f0-9]{64}\z/D', $sourceSha256) !== 1 ||
            $sourceBytes === '' || strlen($sourceBytes) > 150000 ||
            !hash_equals($sourceSha256, hash('sha256', $sourceBytes))) {
            throw new InvalidArgumentException('Invalid approved Onepage snapshot candidate');
        }
    }
}
