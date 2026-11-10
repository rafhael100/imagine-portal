<?php

declare(strict_types=1);

namespace App\Labs\F38;

use App\Domain\ServiceRequests\ServiceRequestSnapshot;
use InvalidArgumentException;

/**
 * A completed fake SQL transaction is NOT customer authorization.
 */
final readonly class F38SyntheticWriteResult
{
    private const REASONS = [
        'applied_synthetic_sql',
        'replayed_synthetic_sql',
        'untrusted_db_role',
        'unsafe_synthetic_schema',
        'missing_synthetic_request',
        'stale_revision',
        'idempotency_conflict',
        'transition_not_permitted',
        'source_unavailable',
        'missing_synthetic_outbox',
    ];

    public function __construct(
        public string $reason,
        public ?ServiceRequestSnapshot $candidate,
    ) {
        if (!in_array($reason,self::REASONS,true)
            || (in_array($reason,['applied_synthetic_sql','replayed_synthetic_sql'],true)
                !== ($candidate !== null))) {
            throw new InvalidArgumentException('Invalid F38 synthetic write result.');
        }
    }

    public static function blocked(string $reason): self
    {
        return new self($reason,null);
    }

    public function wasWrittenToDisposableFixture(): bool
    {
        return $this->reason === 'applied_synthetic_sql';
    }

    public function mayWriteProduction(): bool
    {
        return false;
    }

    public function mayAuthorizeHttp(): bool
    {
        return false;
    }

    public function mayCreateContractOrPayment(): bool
    {
        return false;
    }
}
