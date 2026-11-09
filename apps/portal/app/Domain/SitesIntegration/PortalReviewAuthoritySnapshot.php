<?php

declare(strict_types=1);

namespace App\Domain\SitesIntegration;

use InvalidArgumentException;

/** Pure, FICTIONAL authoritative-state shape, never identity or client approval. */
final readonly class PortalReviewAuthoritySnapshot
{
    public function __construct(
        public string $tenantRef,
        public string $siteRef,
        public string $workflowRef,
        public int $workflowRevision,
        public string $releaseRef,
        public string $artifactDigest,
        public int $authorizationRevision,
        public string $status,
        public int $checkedAt,
        public int $validUntil,
    ) {
        foreach([$tenantRef,$siteRef,$workflowRef,$releaseRef] as $ref) {
            if(!preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D',$ref)) {
                throw new InvalidArgumentException('Invalid fictional site reference.');
            }
        }
        if($workflowRevision<1 || $authorizationRevision<1 ||
            !preg_match('/\A[a-f0-9]{64}\z/D',$artifactDigest) ||
            !in_array($status,['reviewable','revoked','suspended'],true) ||
            $checkedAt<1 || $validUntil<=$checkedAt ||
            $validUntil-$checkedAt>30) {
            throw new InvalidArgumentException('Invalid current-review projection.');
        }
    }
}
