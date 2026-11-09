<?php

declare(strict_types=1);

namespace App\Domain\ServiceRequests;

use InvalidArgumentException;

/**
 * Describes a possible state change. It NEVER authorizes or performs a write.
 * Persisted commands require authentication, entitlement, trusted tenancy,
 * durable idempotency, audit, concurrency checks and separate homologation.
 */
final readonly class ServiceRequestTransitionPreview
{
    private function __construct(
        public string $reason,
        public ?ServiceRequestSnapshot $candidate,
    ) {
        if ($reason === '' || ($reason === 'workflow_valid' && $candidate === null)
            || ($reason !== 'workflow_valid' && $candidate !== null)) {
            throw new InvalidArgumentException('Inconsistent workflow preview.');
        }
    }

    public static function proposed(ServiceRequestSnapshot $candidate): self
    {
        return new self('workflow_valid', $candidate);
    }

    public static function rejected(string $reason): self
    {
        if (!in_array($reason, ['organization_mismatch', 'stale_revision', 'transition_not_permitted'], true)) {
            throw new InvalidArgumentException('Unknown workflow rejection.');
        }
        return new self($reason, null);
    }

    public function isValidBusinessTransition(): bool
    {
        return $this->candidate !== null;
    }

    public function isExecutable(): bool
    {
        // Positive business-state evaluation is never a security decision.
        return false;
    }
}
