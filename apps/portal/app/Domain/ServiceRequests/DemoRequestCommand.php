<?php

declare(strict_types=1);

namespace App\Domain\ServiceRequests;

use App\Domain\Organizations\OrganizationId;
use InvalidArgumentException;

/**
 * F37: fictitious command for testing retry/idempotence and optimistic
 * revisions. ActorKind here is a workflow simulation input, NOT a real
 * authenticated Portal principal or capability.
 */
final readonly class DemoRequestCommand
{
    public function __construct(
        public string $commandId,
        public string $requestId,
        public OrganizationId $organizationId,
        public ServiceRequestActorKind $actorKind,
        public ServiceRequestAction $action,
        public int $expectedRevision,
        public int $occurredAt,
    ) {
        if (preg_match('/\ADEMO-CMD-[A-Z0-9-]{1,60}\z/D', $commandId) !== 1
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $requestId) !== 1
            || $expectedRevision < 0 || $expectedRevision === PHP_INT_MAX
            || $occurredAt < 1 || $occurredAt > 4102444800) {
            throw new InvalidArgumentException('Invalid synthetic service request command.');
        }
    }

    /**
     * Stable binding for a fake idempotency key. Not a cryptographic
     * authorization signature or tamper-proof operational audit record.
     */
    public function fingerprint(): string
    {
        return hash('sha256', json_encode([
            'command' => $this->commandId,
            'request' => $this->requestId,
            'organization' => $this->organizationId->value,
            'actor' => $this->actorKind->value,
            'action' => $this->action->value,
            'revision' => $this->expectedRevision,
            'at' => $this->occurredAt,
        ], JSON_THROW_ON_ERROR));
    }
}
