<?php

declare(strict_types=1);

namespace App\Domain\ServiceRequests;

use InvalidArgumentException;

/**
 * In-memory dry-run outcome ONLY. A positive business-state transition
 * cannot grant HTTP access, persist customer state or authorize billing.
 */
final readonly class DemoRequestJournalOutcome
{
    private const REASONS = [
        'applied_in_memory',
        'replayed_in_memory',
        'idempotency_conflict',
        'organization_mismatch',
        'request_mismatch',
        'stale_revision',
        'transition_not_permitted',
        'lab_event_limit',
    ];

    public function __construct(
        public string $reason,
        public ?ServiceRequestSnapshot $candidate,
    ) {
        if (!in_array($reason, self::REASONS, true)
            || (in_array($reason,['applied_in_memory','replayed_in_memory'],true)
                && $candidate === null)
            || (!in_array($reason,['applied_in_memory','replayed_in_memory'],true)
                && $candidate !== null)) {
            throw new InvalidArgumentException('Invalid dry-run journal outcome.');
        }
    }

    public function isAcceptedInMemory(): bool
    {
        return $this->candidate !== null;
    }

    public function mayWriteDatabase(): bool
    {
        return false;
    }

    public function mayAuthorizeHttp(): bool
    {
        return false;
    }

    public function mayCreateContractOrCharge(): bool
    {
        return false;
    }
}
