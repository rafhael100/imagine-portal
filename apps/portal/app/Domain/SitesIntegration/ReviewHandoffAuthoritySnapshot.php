<?php

declare(strict_types=1);

namespace App\Domain\SitesIntegration;

use InvalidArgumentException;

/** An independent adapter result, NOT a user-supplied authority token. */
final readonly class ReviewHandoffAuthoritySnapshot
{
    public function __construct(
        public string $tenantRef,
        public string $siteRef,
        public string $workflowRef,
        public int $workflowRevision,
        public string $releaseRef,
        public string $artifactDigest,
        public int $authorityRevision,
        public bool $currentlyEligible,
    ) {
        foreach ([$tenantRef,$siteRef,$workflowRef,$releaseRef] as $ref) {
            if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D',$ref)) {
                throw new InvalidArgumentException('Unsafe review snapshot identifier.');
            }
        }
        if ($workflowRevision < 1 || $authorityRevision < 1 ||
            !preg_match('/\A[a-f0-9]{64}\z/D',$artifactDigest)) {
            throw new InvalidArgumentException('Invalid current review snapshot version or SHA-256.');
        }
    }
}
