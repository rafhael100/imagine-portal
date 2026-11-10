<?php

declare(strict_types=1);

namespace App\Labs\F44;

use InvalidArgumentException;

/**
 * A successful mock PostgreSQL review is NOT a verified customer approval.
 */
final readonly class F44SyntheticReviewWriteResult
{
    private const REASONS=[
        'applied_synthetic_sql',
        'replayed_synthetic_sql',
        'organization_mismatch',
        'reference_mismatch',
        'content_changed',
        'untrusted_demo_actor',
        'untrusted_sql_role',
        'unsafe_synthetic_schema',
        'missing_demo_candidate',
        'idempotency_conflict',
        'stale_review_revision',
        'review_already_completed',
        'source_unavailable',
    ];

    public function __construct(
        public string $reason,
        public ?string $simulatedStatus,
        public ?int $reviewRevision,
    ) {
        $success=in_array($reason,['applied_synthetic_sql','replayed_synthetic_sql'],true);
        if (!in_array($reason,self::REASONS,true)
            || $success!==($simulatedStatus!==null)
            || $success!==($reviewRevision!==null)
            || ($simulatedStatus!==null && !in_array($simulatedStatus,[
                'approved_demo','changes_requested_demo','rejected_demo',
            ],true))) {
            throw new InvalidArgumentException('Invalid F44 SQL review fixture result.');
        }
    }

    public static function denied(string $reason): self
    {
        return new self($reason,null,null);
    }

    public function wasWrittenOnlyToDisposableFixture(): bool
    {
        return $this->reason==='applied_synthetic_sql';
    }

    public function isSimulatedApproval(): bool
    {
        return $this->simulatedStatus==='approved_demo';
    }

    public function authorizesPublishing(): bool
    {
        return false;
    }

    public function isVerifiedConsent(): bool
    {
        return false;
    }

    public function authorizesContractOrCharge(): bool
    {
        return false;
    }
}
