<?php

declare(strict_types=1);

namespace App\Domain\ServiceRequests;

use App\Domain\Organizations\OrganizationId;

/**
 * Pure, side-effect-free REQUEST WORKFLOW PREVIEW.
 *
 * The organization and actor kind are caller-supplied INPUT, not trusted
 * identity. Even a valid transition is NOT permission to access customer data
 * or execute anything. Never expose as a write endpoint.
 */
final class ServiceRequestWorkflowPreview
{
    public function evaluate(
        ServiceRequestSnapshot $request,
        OrganizationId $selectedOrganization,
        ServiceRequestActorKind $actor,
        ServiceRequestAction $action,
        int $expectedRevision,
    ): ServiceRequestTransitionPreview {
        if (!$request->organizationId->equals($selectedOrganization)) {
            return ServiceRequestTransitionPreview::rejected('organization_mismatch');
        }
        if ($request->revision !== $expectedRevision) {
            return ServiceRequestTransitionPreview::rejected('stale_revision');
        }

        $next = match ($request->status) {
            ServiceRequestStatus::Draft =>
                $actor === ServiceRequestActorKind::Customer && $action === ServiceRequestAction::Submit
                    ? ServiceRequestStatus::Submitted : null,
            ServiceRequestStatus::Submitted =>
                $actor === ServiceRequestActorKind::ImagineTeam && $action === ServiceRequestAction::StartReview
                    ? ServiceRequestStatus::InReview : null,
            ServiceRequestStatus::InReview => match (true) {
                $actor === ServiceRequestActorKind::ImagineTeam && $action === ServiceRequestAction::RequestInformation
                    => ServiceRequestStatus::WaitingOnCustomer,
                $actor === ServiceRequestActorKind::ImagineTeam && $action === ServiceRequestAction::Resolve
                    => ServiceRequestStatus::Resolved,
                default => null,
            },
            ServiceRequestStatus::WaitingOnCustomer =>
                $actor === ServiceRequestActorKind::Customer && $action === ServiceRequestAction::ProvideInformation
                    ? ServiceRequestStatus::InReview : null,
            ServiceRequestStatus::Resolved => match (true) {
                $actor === ServiceRequestActorKind::ImagineTeam && $action === ServiceRequestAction::Close
                    => ServiceRequestStatus::Closed,
                $actor === ServiceRequestActorKind::ImagineTeam && $action === ServiceRequestAction::Reopen
                    => ServiceRequestStatus::InReview,
                default => null,
            },
            ServiceRequestStatus::Closed => null,
        };

        if ($next === null) {
            return ServiceRequestTransitionPreview::rejected('transition_not_permitted');
        }

        return ServiceRequestTransitionPreview::proposed(
            new ServiceRequestSnapshot(
                $request->requestId,
                $request->organizationId,
                $request->serviceReference,
                $next,
                $request->revision + 1,
            ),
        );
    }
}
