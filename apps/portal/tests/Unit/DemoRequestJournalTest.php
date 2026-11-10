<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Organizations\OrganizationId;
use App\Domain\ServiceRequests\DemoRequestCommand;
use App\Domain\ServiceRequests\DemoRequestJournal;
use App\Domain\ServiceRequests\DemoRequestJournalOutcome;
use App\Domain\ServiceRequests\ServiceRequestAction as Action;
use App\Domain\ServiceRequests\ServiceRequestActorKind as Actor;
use App\Domain\ServiceRequests\ServiceRequestSnapshot;
use App\Domain\ServiceRequests\ServiceRequestStatus as Status;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class DemoRequestJournalTest extends TestCase
{
    private function journal(string $tenant='org-a'): DemoRequestJournal
    {
        return new DemoRequestJournal(new ServiceRequestSnapshot(
            'demo-request-001',
            new OrganizationId($tenant),
            'sites-onepage-demo',
            Status::Draft,
            0,
        ));
    }

    private function command(
        int $sequence,
        Actor $actor=Actor::Customer,
        Action $action=Action::Submit,
        int $revision=0,
        string $org='org-a',
        string $request='demo-request-001',
    ): DemoRequestCommand {
        return new DemoRequestCommand(
            'DEMO-CMD-'.str_pad((string)$sequence,3,'0',STR_PAD_LEFT),
            $request,new OrganizationId($org),$actor,$action,
            $revision,1800000000+$sequence,
        );
    }

    public function test_full_request_lifecycle_records_an_append_only_synthetic_event_chain(): void
    {
        $journal=$this->journal();
        $workflow=[
            [Actor::Customer,Action::Submit,Status::Submitted],
            [Actor::ImagineTeam,Action::StartReview,Status::InReview],
            [Actor::ImagineTeam,Action::RequestInformation,Status::WaitingOnCustomer],
            [Actor::Customer,Action::ProvideInformation,Status::InReview],
            [Actor::ImagineTeam,Action::Resolve,Status::Resolved],
            [Actor::ImagineTeam,Action::Close,Status::Closed],
        ];
        foreach($workflow as $index=>[$actor,$action,$state]){
            $outcome=$journal->apply($this->command(
                $index+1,$actor,$action,$index,
            ));
            self::assertSame('applied_in_memory',$outcome->reason);
            self::assertTrue($outcome->isAcceptedInMemory());
            self::assertSame($state,$outcome->candidate?->status);
            self::assertSame($index+1,$outcome->candidate?->revision);
            self::assertFalse($outcome->mayWriteDatabase());
            self::assertFalse($outcome->mayAuthorizeHttp());
            self::assertFalse($outcome->mayCreateContractOrCharge());
        }
        self::assertSame(Status::Closed,$journal->currentSnapshot()->status);
        self::assertCount(6,$journal->syntheticEventHistory());
        self::assertSame('draft',$journal->syntheticEventHistory()[0]['from']);
        self::assertSame('closed',$journal->syntheticEventHistory()[5]['to']);
        self::assertFalse($journal->journalCanAuthorizeHttp());
        self::assertFalse($journal->journalIsDurable());
    }

    public function test_exact_idempotent_replay_preserves_original_response_after_later_changes(): void
    {
        $journal=$this->journal();
        $first=$this->command(1);
        $accepted=$journal->apply($first);
        self::assertSame('applied_in_memory',$accepted->reason);
        $second=$this->command(2,Actor::ImagineTeam,Action::StartReview,1);
        self::assertSame(2,$journal->apply($second)->candidate?->revision);

        $replay=$journal->apply($first);
        self::assertSame('replayed_in_memory',$replay->reason);
        self::assertSame(1,$replay->candidate?->revision);
        self::assertSame(Status::Submitted,$replay->candidate?->status);
        self::assertSame(2,$journal->currentSnapshot()->revision);
        self::assertCount(2,$journal->syntheticEventHistory());
        self::assertFalse($replay->mayAuthorizeHttp());
    }

    public function test_same_command_id_with_different_payload_fails_closed_without_mutation(): void
    {
        $journal=$this->journal();
        self::assertTrue($journal->apply($this->command(1))->isAcceptedInMemory());
        $changed=$this->command(1,Actor::ImagineTeam,Action::StartReview,1);
        $result=$journal->apply($changed);
        self::assertSame('idempotency_conflict',$result->reason);
        self::assertFalse($result->isAcceptedInMemory());
        self::assertSame(1,$journal->currentSnapshot()->revision);
        self::assertCount(1,$journal->syntheticEventHistory());
    }

    public function test_cross_tenant_and_wrong_request_are_denied_before_idempotency_lookup(): void
    {
        $journal=$this->journal();
        self::assertSame('applied_in_memory',$journal->apply($this->command(1))->reason);
        $otherOrg=$journal->apply($this->command(1,org:'org-b'));
        self::assertSame('organization_mismatch',$otherOrg->reason);
        $wrongRequest=$journal->apply($this->command(1,request:'other-request'));
        self::assertSame('request_mismatch',$wrongRequest->reason);
        self::assertSame(1,$journal->currentSnapshot()->revision);
        self::assertCount(1,$journal->syntheticEventHistory());
        self::assertFalse($otherOrg->mayWriteDatabase());
    }

    public function test_stale_revision_and_forbidden_role_do_not_consume_idempotency_key(): void
    {
        $journal=$this->journal();
        $stale=$journal->apply($this->command(1,revision:7));
        self::assertSame('stale_revision',$stale->reason);
        $wrongRole=$journal->apply($this->command(1,Actor::ImagineTeam));
        self::assertSame('transition_not_permitted',$wrongRole->reason);
        self::assertSame(0,$journal->currentSnapshot()->revision);
        self::assertSame([],$journal->syntheticEventHistory());

        // Same key is still available for a valid synthetic business step.
        self::assertSame('applied_in_memory',$journal->apply($this->command(1))->reason);
        self::assertSame(1,$journal->currentSnapshot()->revision);
    }

    public function test_opaque_references_cannot_include_path_traversal_or_unbounded_values(): void
    {
        foreach(['','../foo','bad id',str_repeat('X',70),'demo-cmd-lowercase'] as $id){
            try{
                new DemoRequestCommand(
                    $id,'req',new OrganizationId('org-a'),
                    Actor::Customer,Action::Submit,0,1800000000,
                );
                self::fail('Invalid demo command ID accepted.');
            }catch(InvalidArgumentException){
                self::addToAssertionCount(1);
            }
        }
        foreach([-1,PHP_INT_MAX] as $rev){
            try{
                new DemoRequestCommand(
                    'DEMO-CMD-001','req',new OrganizationId('org-a'),
                    Actor::Customer,Action::Submit,$rev,1800000000,
                );
                self::fail('Invalid expected revision accepted.');
            }catch(InvalidArgumentException){
                self::addToAssertionCount(1);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        new DemoRequestCommand(
            'DEMO-CMD-001','req',new OrganizationId('org-a'),
            Actor::Customer,Action::Submit,0,0,
        );
    }

    public function test_demo_event_limit_is_closed_and_never_mutates_after_fifty_events(): void
    {
        $journal=$this->journal();
        self::assertTrue($journal->apply($this->command(1))->isAcceptedInMemory());
        self::assertTrue($journal->apply(
            $this->command(2,Actor::ImagineTeam,Action::StartReview,1),
        )->isAcceptedInMemory());
        for($step=3;$step<=50;$step++){
            $action=$step%2===1 ? Action::Resolve : Action::Reopen;
            $out=$journal->apply($this->command(
                $step,Actor::ImagineTeam,$action,$step-1,
            ));
            self::assertSame('applied_in_memory',$out->reason);
        }
        self::assertSame(50,$journal->currentSnapshot()->revision);
        self::assertCount(50,$journal->syntheticEventHistory());
        $over=$journal->apply($this->command(51,Actor::ImagineTeam,Action::Resolve,50));
        self::assertSame('lab_event_limit',$over->reason);
        self::assertSame(50,$journal->currentSnapshot()->revision);
        self::assertCount(50,$journal->syntheticEventHistory());
        self::assertFalse($over->mayWriteDatabase());
    }

    public function test_journal_is_deterministic_but_never_an_audit_trust_anchor(): void
    {
        $a=$this->journal();
        $b=$this->journal();
        $commands=[
            $this->command(1),
            $this->command(2,Actor::ImagineTeam,Action::StartReview,1),
            $this->command(3,Actor::ImagineTeam,Action::Resolve,2),
        ];
        foreach($commands as $command){
            self::assertSame($a->apply($command)->reason,$b->apply($command)->reason);
            self::assertSame(64,strlen($command->fingerprint()));
        }
        self::assertSame($a->syntheticEventHistory(),$b->syntheticEventHistory());
        self::assertNotSame($a,$b);
        self::assertFalse($a->journalIsDurable());
        self::assertFalse($a->journalCanAuthorizeHttp());
        self::assertTrue((new \ReflectionClass(DemoRequestCommand::class))->isReadOnly());
        self::assertTrue((new \ReflectionClass(DemoRequestJournalOutcome::class))->isReadOnly());
    }

    public function test_outcome_rejects_unknown_operational_reason_or_mismatched_candidate(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new DemoRequestJournalOutcome('authorized_for_production',null);
    }
}
