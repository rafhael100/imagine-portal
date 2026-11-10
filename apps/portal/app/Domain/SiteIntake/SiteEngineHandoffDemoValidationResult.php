<?php

declare(strict_types=1);

namespace App\Domain\SiteIntake;

use InvalidArgumentException;

/**
 * Validation is only of a caller-controlled DEMO contract against a local
 * fake candidate/journal. It cannot authenticate an external system.
 */
final readonly class SiteEngineHandoffDemoValidationResult
{
    private const REASONS=[
        'matching_demo_preview',
        'local_review_unavailable',
        'payload_too_large',
        'malformed_json',
        'noncanonical_or_mismatch',
    ];

    public function __construct(
        public string $reason,
        public ?string $canonicalFingerprint,
    ) {
        if (!in_array($reason,self::REASONS,true)
            || (($reason==='matching_demo_preview')!==($canonicalFingerprint!==null))
            || ($canonicalFingerprint!==null
                && preg_match('/\A[a-f0-9]{64}\z/D',$canonicalFingerprint)!==1)) {
            throw new InvalidArgumentException('Invalid F49 preview validation result.');
        }
    }

    public static function denied(string $reason): self
    {
        return new self($reason,null);
    }

    public function matchesSyntheticContract(): bool
    {
        return $this->canonicalFingerprint!==null;
    }

    public function isAuthenticatedTransport(): bool
    {
        return false;
    }

    public function isVerifiedCustomerApproval(): bool
    {
        return false;
    }

    public function permitsSitePublishing(): bool
    {
        return false;
    }
}
