<?php

declare(strict_types=1);

namespace App\Domain\SiteIntake;

/**
 * F43: one candidate, one fake editorial decision, replay-safe in memory.
 *
 * Content revision/hash, site reference, tenant and demo actor MUST match.
 * A new content revision creates a NEW journal with no inherited approval.
 * No actual signatures, identity, customer approval, storage or publishing.
 */
final class DemoSiteReviewJournal
{
    private ?string $status=null;
    private int $reviewRevision=0;

    /** @var array<string,array{fingerprint:string,outcome:SiteReviewOutcome}> */
    private array $receipts=[];

    /** @var list<array<string,string|int>> */
    private array $events=[];

    public function __construct(public readonly SiteContentCandidate $candidate)
    {
    }

    public function apply(SiteReviewCommand $command): SiteReviewOutcome
    {
        $brief=$this->candidate->brief;
        if (!$command->organization->equals($brief->organizationId)) {
            return SiteReviewOutcome::denied('organization_mismatch');
        }
        if ($command->siteReference!==$brief->reference) {
            return SiteReviewOutcome::denied('reference_mismatch');
        }
        if (!hash_equals($this->candidate->fingerprint,$command->contentFingerprint)) {
            return SiteReviewOutcome::denied('content_changed');
        }

        // Demo actors are syntactic fixture labels, not authentication.
        $actor=$brief->organizationId->value==='org-a'
            ? 'DEMO-REVIEWER-A' : 'DEMO-REVIEWER-B';
        if ($command->actor!==$actor) {
            return SiteReviewOutcome::denied('untrusted_demo_actor');
        }

        $existing=$this->receipts[$command->commandId] ?? null;
        if ($existing!==null) {
            if (!hash_equals($existing['fingerprint'],$command->fingerprint())) {
                return SiteReviewOutcome::denied('idempotency_conflict');
            }
            return new SiteReviewOutcome(
                'replayed_in_memory',
                $existing['outcome']->simulatedStatus,
                $existing['outcome']->reviewRevision,
            );
        }

        if ($command->expectedReviewRevision!==$this->reviewRevision) {
            return SiteReviewOutcome::denied('stale_review_revision');
        }
        if ($this->status!==null) {
            return SiteReviewOutcome::denied('review_already_completed');
        }

        $status=match($command->decision) {
            SiteReviewDecision::Approve=>'approved_demo',
            SiteReviewDecision::RequestChanges=>'changes_requested_demo',
            SiteReviewDecision::Reject=>'rejected_demo',
        };
        $reason=match($command->decision) {
            SiteReviewDecision::Approve=>'approved_in_memory',
            SiteReviewDecision::RequestChanges=>'changes_requested_in_memory',
            SiteReviewDecision::Reject=>'rejected_in_memory',
        };
        $this->status=$status;
        $this->reviewRevision++;
        $outcome=new SiteReviewOutcome($reason,$status,$this->reviewRevision);
        $this->events[]=[
            'command'=>$command->commandId,
            'reference'=>$brief->reference,
            'organization'=>$brief->organizationId->value,
            'brief_revision'=>$brief->revision,
            'content_revision'=>$this->candidate->contentRevision,
            'content_sha256'=>$this->candidate->fingerprint,
            'actor'=>$command->actor,
            'decision'=>$command->decision->value,
            'review_revision'=>$this->reviewRevision,
            'occurred_at'=>$command->occurredAt,
        ];
        $this->receipts[$command->commandId]=[
            'fingerprint'=>$command->fingerprint(),
            'outcome'=>$outcome,
        ];

        return $outcome;
    }

    public function currentSimulatedStatus(): string
    {
        return $this->status ?? 'awaiting_review_demo';
    }

    public function currentReviewRevision(): int
    {
        return $this->reviewRevision;
    }

    /** @return list<array<string,string|int>> */
    public function syntheticHistory(): array
    {
        return $this->events;
    }

    public function canPublishSite(): bool
    {
        return false;
    }

    public function isSignedApproval(): bool
    {
        return false;
    }

    public function hasVerifiedCustomerIdentity(): bool
    {
        return false;
    }
}
