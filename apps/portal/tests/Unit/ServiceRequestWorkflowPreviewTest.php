<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Organizations\OrganizationId;
use App\Domain\ServiceRequests\ServiceRequestAction as Action;
use App\Domain\ServiceRequests\ServiceRequestActorKind as Actor;
use App\Domain\ServiceRequests\ServiceRequestSnapshot;
use App\Domain\ServiceRequests\ServiceRequestStatus as Status;
use App\Domain\ServiceRequests\ServiceRequestTransitionPreview;
use App\Domain\ServiceRequests\ServiceRequestWorkflowPreview;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ServiceRequestWorkflowPreviewTest extends TestCase
{
    private function snapshot(Status $status = Status::Draft, int $revision = 0): ServiceRequestSnapshot
    {
        return new ServiceRequestSnapshot(
            'request-001',
            new OrganizationId('portal-org-001'),
            'sites-onepage-001',
            $status,
            $revision,
        );
    }

    public function test_complete_customer_and_staff_workflow_is_versioned_and_only_previewed(): void
    {
        $flow = new ServiceRequestWorkflowPreview();
        $steps = [
            [Actor::Customer, Action::Submit, Status::Submitted],
            [Actor::ImagineTeam, Action::StartReview, Status::InReview],
            [Actor::ImagineTeam, Action::RequestInformation, Status::WaitingOnCustomer],
            [Actor::Customer, Action::ProvideInformation, Status::InReview],
            [Actor::ImagineTeam, Action::Resolve, Status::Resolved],
            [Actor::ImagineTeam, Action::Close, Status::Closed],
        ];
        $current = $this->snapshot();
        foreach ($steps as [$actor, $action, $status]) {
            $originalRevision = $current->revision;
            $result = $flow->evaluate($current, $current->organizationId, $actor, $action, $originalRevision);
            self::assertTrue($result->isValidBusinessTransition());
            self::assertFalse($result->isExecutable());
            self::assertSame('workflow_valid', $result->reason);
            self::assertNotNull($result->candidate);
            self::assertSame($status, $result->candidate->status);
            self::assertSame($originalRevision + 1, $result->candidate->revision);
            self::assertSame($originalRevision, $current->revision);
            self::assertSame($current->organizationId, $result->candidate->organizationId);
            self::assertSame($current->serviceReference, $result->candidate->serviceReference);
            $current = $result->candidate;
        }

        $closed = $flow->evaluate($current, $current->organizationId, Actor::ImagineTeam, Action::Reopen, $current->revision);
        self::assertFalse($closed->isValidBusinessTransition());
        self::assertFalse($closed->isExecutable());
        self::assertNull($closed->candidate);
    }

    public function test_resolved_request_can_be_reopened_for_review_by_team_before_closure(): void
    {
        $request = $this->snapshot(Status::Resolved, 4);
        $result = (new ServiceRequestWorkflowPreview())->evaluate(
            $request, $request->organizationId, Actor::ImagineTeam, Action::Reopen, 4,
        );
        self::assertSame(Status::InReview, $result->candidate?->status);
        self::assertSame(5, $result->candidate?->revision);
        self::assertFalse($result->isExecutable());
    }

    public function test_cross_organization_is_denied_even_for_otherwise_valid_action(): void
    {
        $request = $this->snapshot();
        $result = (new ServiceRequestWorkflowPreview())->evaluate(
            $request, new OrganizationId('portal-org-OTHER'), Actor::Customer, Action::Submit, 0,
        );
        self::assertSame('organization_mismatch', $result->reason);
        self::assertFalse($result->isValidBusinessTransition());
        self::assertNull($result->candidate);
    }

    public function test_stale_or_future_revision_is_denied_without_mutating_input(): void
    {
        $request = $this->snapshot(Status::Submitted, 2);
        foreach ([1, 3, -1] as $revision) {
            $result = (new ServiceRequestWorkflowPreview())->evaluate(
                $request, $request->organizationId, Actor::ImagineTeam, Action::StartReview, $revision,
            );
            self::assertSame('stale_revision', $result->reason);
            self::assertNull($result->candidate);
        }
        self::assertSame(Status::Submitted, $request->status);
        self::assertSame(2, $request->revision);
    }

    public function test_role_and_action_matrix_rejects_unauthorized_business_transitions(): void
    {
        $flow = new ServiceRequestWorkflowPreview();
        $cases = [
            [Status::Draft, Actor::ImagineTeam, Action::Submit],
            [Status::Submitted, Actor::Customer, Action::StartReview],
            [Status::Submitted, Actor::ImagineTeam, Action::Resolve],
            [Status::InReview, Actor::Customer, Action::Resolve],
            [Status::InReview, Actor::Customer, Action::ProvideInformation],
            [Status::WaitingOnCustomer, Actor::ImagineTeam, Action::ProvideInformation],
            [Status::Resolved, Actor::Customer, Action::Close],
            [Status::Resolved, Actor::Customer, Action::Reopen],
            [Status::Closed, Actor::ImagineTeam, Action::StartReview],
            [Status::Closed, Actor::Customer, Action::Submit],
        ];
        foreach ($cases as [$status, $role, $action]) {
            $request = $this->snapshot($status, 7);
            $result = $flow->evaluate($request, $request->organizationId, $role, $action, 7);
            self::assertSame('transition_not_permitted', $result->reason);
            self::assertNull($result->candidate);
            self::assertFalse($result->isExecutable());
        }
    }

    public function test_input_references_and_revision_cannot_encode_paths_or_unbounded_values(): void
    {
        foreach (['', '../escape', 'contains space', 'bad/slash', str_repeat('x', 129)] as $value) {
            try {
                new ServiceRequestSnapshot($value, new OrganizationId('org'), 'service', Status::Draft, 0);
                self::fail('Invalid request ID accepted.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
            try {
                new ServiceRequestSnapshot('request', new OrganizationId('org'), $value, Status::Draft, 0);
                self::fail('Invalid service reference accepted.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        foreach ([-1, PHP_INT_MAX] as $revision) {
            try {
                new ServiceRequestSnapshot('request', new OrganizationId('org'), 'service', Status::Draft, $revision);
                self::fail('Invalid revision accepted.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_decision_is_not_write_authorization_and_cannot_invent_rejection_reason(): void
    {
        self::assertFalse(ServiceRequestTransitionPreview::rejected('stale_revision')->isExecutable());
        self::assertFalse(ServiceRequestTransitionPreview::proposed($this->snapshot())->isExecutable());

        $this->expectException(InvalidArgumentException::class);
        ServiceRequestTransitionPreview::rejected('approved_for_write');
    }

    public function test_snapshots_and_decisions_are_readonly_and_unknown_actions_do_not_exist(): void
    {
        self::assertTrue((new \ReflectionClass(ServiceRequestSnapshot::class))->isReadOnly());
        self::assertTrue((new \ReflectionClass(ServiceRequestTransitionPreview::class))->isReadOnly());
        self::assertNull(Action::tryFrom('delete_organization'));
        self::assertNull(Actor::tryFrom('superadmin'));
        self::assertNull(Status::tryFrom('paid'));
    }
}
