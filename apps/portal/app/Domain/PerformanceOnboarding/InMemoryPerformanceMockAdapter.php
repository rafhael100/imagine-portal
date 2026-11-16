<?php

namespace App\Domain\PerformanceOnboarding;

use App\Domain\Authorization\AuthorizationContext;
use App\Domain\Authorization\OrganizationAuthorizationService;
use App\Domain\Authorization\Permission;
use App\Domain\Identity\PortalUserId;
use App\Domain\Organizations\OrganizationId;
use App\Domain\Organizations\OrganizationMembership;

/**
 * Local memory-only adapter, not a service client, queue, API or signed event
 * consumer. Event IDs prevent repeat handling only within THIS mock instance.
 * Real durable idempotency/authentication/retries need separate homologation.
 */
final class InMemoryPerformanceMockAdapter
{
    /** @var array<string,array{fingerprint:string,receipt:DemoAdapterReceipt}> */
    private array $events = [];

    /** @var array<string,string> */
    private array $drafts = [];

    public function handle(
        DemoOnboardingDraft $draft,
        DemoPerformanceEvent $event,
        ?PortalUserId $actor,
        ?OrganizationId $selectedOrganization,
        ?OrganizationMembership $membership,
    ): ?DemoAdapterReceipt {
        if ($actor === null || $selectedOrganization === null
            || ! $draft->organizationId->equals($selectedOrganization)
            || ! $draft->requestingActorId->equals($actor)
            || ! $event->organizationId->equals($draft->organizationId)
            || ! $event->onboardingId->equals($draft->id)
            || ! hash_equals($draft->proposalFingerprint, $event->proposalFingerprint)) {
            return null;
        }

        $decision = (new OrganizationAuthorizationService())->decide(
            new AuthorizationContext(
                $actor, $selectedOrganization, $draft->organizationId,
                Permission::ManageServices->value,
            ),
            $membership,
        );

        if (! $decision->allowed) {
            return null;
        }

        $fingerprint = $event->fingerprint();
        if (isset($this->events[$event->eventId])) {
            $previous = $this->events[$event->eventId];

            // Exact replay is idempotent. Same ID with changed envelope is denied.
            return hash_equals($previous['fingerprint'], $fingerprint)
                ? $previous['receipt']
                : null;
        }

        $draftKey = $draft->organizationId->value.':'.$draft->id->value;

        // A second different event cannot create another mock receipt for a draft.
        if (isset($this->drafts[$draftKey])) {
            return null;
        }

        $receipt = new DemoAdapterReceipt(
            $event->eventId,
            $event->correlationId,
            $draft->organizationId,
            $draft->id,
        );

        $this->events[$event->eventId] = ['fingerprint' => $fingerprint, 'receipt' => $receipt];
        $this->drafts[$draftKey] = $event->eventId;

        return $receipt;
    }

    public function handledCount(): int
    {
        return count($this->events);
    }
}
