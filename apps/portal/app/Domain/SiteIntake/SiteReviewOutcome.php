<?php

declare(strict_types=1);

namespace App\Domain\SiteIntake;

/** F43 simulated editorial result only; NEVER a publishing authorization. */
final readonly class SiteReviewOutcome
{
    private const REASONS=[
        'approved_in_memory',
        'changes_requested_in_memory',
        'rejected_in_memory',
        'replayed_in_memory',
        'organization_mismatch',
        'reference_mismatch',
        'content_changed',
        'untrusted_demo_actor',
        'idempotency_conflict',
        'stale_review_revision',
        'review_already_completed',
    ];

    public function __construct(
        public string $reason,
        public ?string $simulatedStatus,
        public ?int $reviewRevision,
    ) {
        $accepted=in_array($reason,[
            'approved_in_memory',
            'changes_requested_in_memory',
            'rejected_in_memory',
            'replayed_in_memory',
        ],true);
        if (!in_array($reason,self::REASONS,true)
            || ($accepted !== ($simulatedStatus!==null))
            || ($accepted !== ($reviewRevision!==null))
            || ($simulatedStatus!==null && !in_array($simulatedStatus,[
                'approved_demo','changes_requested_demo','rejected_demo',
            ],true))) {
            throw new \InvalidArgumentException('Invalid simulated Sites review outcome.');
        }
    }

    public static function denied(string $reason): self
    {
        return new self($reason,null,null);
    }

    public function isSimulatedApproval(): bool
    {
        return $this->simulatedStatus==='approved_demo';
    }

    public function mayPublishSite(): bool
    {
        return false;
    }

    public function mayActivateContract(): bool
    {
        return false;
    }

    public function isVerifiedClientConsent(): bool
    {
        return false;
    }
}
