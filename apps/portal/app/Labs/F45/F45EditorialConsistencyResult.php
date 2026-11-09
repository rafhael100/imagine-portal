<?php

declare(strict_types=1);

namespace App\Labs\F45;

use InvalidArgumentException;

/** A purely synthetic consistency verdict; never a publication authorization. */
final readonly class F45EditorialConsistencyResult
{
    private const REASONS=[
        'consistent_approved_demo',
        'missing_demo_candidate',
        'content_changed',
        'not_approved',
        'missing_receipt',
        'ambiguous_receipts',
        'receipt_mismatch',
        'untrusted_sql_role',
        'unsafe_synthetic_schema',
        'source_unavailable',
    ];

    public function __construct(public string $reason)
    {
        if (!in_array($reason,self::REASONS,true)) {
            throw new InvalidArgumentException('Invalid F45 fake release consistency result.');
        }
    }

    public function isConsistentInLab(): bool
    {
        return $this->reason==='consistent_approved_demo';
    }

    public function mayPublishSite(): bool
    {
        return false;
    }

    public function isVerifiedClientApproval(): bool
    {
        return false;
    }

    public function mayAuthorizeContractOrCharge(): bool
    {
        return false;
    }
}
