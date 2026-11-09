<?php

declare(strict_types=1);

namespace App\Domain\ServiceRequests;

/**
 * F37 reproducible in-memory request journal. This is not a repository,
 * database, durable outbox, a command-handler for HTTP or an authorization
 * service. All actor and organization inputs are deliberately synthetic.
 *
 * A real write path still requires verified Portal principal, membership,
 * service ownership, transactional RLS, DB-enforced idempotency/version,
 * audit, rollback/revocation safeguards and independent AppSec approval.
 */
final class DemoRequestJournal
{
    private const MAX_EVENTS = 50;

    private ServiceRequestSnapshot $current;

    /** @var array<string,array{fingerprint:string,outcome:DemoRequestJournalOutcome}> */
    private array $receipts = [];

    /** @var list<array<string,int|string>> */
    private array $events = [];

    public function __construct(
        ServiceRequestSnapshot $initial,
        private readonly ServiceRequestWorkflowPreview $flow = new ServiceRequestWorkflowPreview(),
    ) {
        $this->current = $initial;
    }

    public function apply(DemoRequestCommand $command): DemoRequestJournalOutcome
    {
        // Tenant and resource mismatch must be rejected BEFORE looking up
        // idempotency keys to avoid leaking keys across organizations.
        if (!$this->current->organizationId->equals($command->organizationId)) {
            return new DemoRequestJournalOutcome('organization_mismatch',null);
        }
        if ($this->current->requestId !== $command->requestId) {
            return new DemoRequestJournalOutcome('request_mismatch',null);
        }

        $existing = $this->receipts[$command->commandId] ?? null;
        if ($existing !== null) {
            if (!hash_equals($existing['fingerprint'], $command->fingerprint())) {
                return new DemoRequestJournalOutcome('idempotency_conflict',null);
            }
            // The snapshot returned by an exact replay is the one from the
            // original command, not the possibly newer current revision.
            return new DemoRequestJournalOutcome(
                'replayed_in_memory',
                $existing['outcome']->candidate,
            );
        }

        if ($this->current->revision !== $command->expectedRevision) {
            return new DemoRequestJournalOutcome('stale_revision',null);
        }
        if (count($this->events) >= self::MAX_EVENTS) {
            return new DemoRequestJournalOutcome('lab_event_limit',null);
        }
        $preview = $this->flow->evaluate(
            $this->current,
            $command->organizationId,
            $command->actorKind,
            $command->action,
            $command->expectedRevision,
        );
        if (!$preview->isValidBusinessTransition()
            || $preview->candidate === null
            || $preview->isExecutable()) {
            return new DemoRequestJournalOutcome(
                'transition_not_permitted',null,
            );
        }

        $previous = $this->current;
        $this->current = $preview->candidate;
        $outcome = new DemoRequestJournalOutcome('applied_in_memory',$this->current);
        $this->events[] = [
            'command' => $command->commandId,
            'request' => $command->requestId,
            'organization' => $command->organizationId->value,
            'actor_kind' => $command->actorKind->value,
            'action' => $command->action->value,
            'from' => $previous->status->value,
            'to' => $this->current->status->value,
            'previous_revision' => $previous->revision,
            'revision' => $this->current->revision,
            'occurred_at' => $command->occurredAt,
        ];
        $this->receipts[$command->commandId] = [
            'fingerprint' => $command->fingerprint(),
            'outcome' => $outcome,
        ];
        return $outcome;
    }

    public function currentSnapshot(): ServiceRequestSnapshot
    {
        return $this->current;
    }

    /** @return list<array<string,int|string>> */
    public function syntheticEventHistory(): array
    {
        return $this->events;
    }

    public function journalIsDurable(): bool
    {
        return false;
    }

    public function journalCanAuthorizeHttp(): bool
    {
        return false;
    }
}
