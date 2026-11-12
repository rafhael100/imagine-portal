<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Organizations\OrganizationId;
use App\Domain\ServicePortfolio\PortfolioProduct;
use App\Domain\SiteIntake\DemoSiteReviewJournal;
use App\Domain\SiteIntake\SiteBriefDraft;
use App\Domain\SiteIntake\SiteContentCandidate;
use App\Domain\SiteIntake\SiteReviewCommand;
use App\Domain\SiteIntake\SiteReviewDecision;
use App\Domain\SiteIntake\SiteReviewOutcome;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class DemoSiteReviewJournalTest extends TestCase
{
    private function brief(string $tenant='a',int $revision=0): SiteBriefDraft
    {
        return new SiteBriefDraft(
            'DEMO-SITE-REQ-'.strtoupper($tenant).'1',
            new OrganizationId('org-'.$tenant),
            PortfolioProduct::Onepage,
            'Apresentar serviços com clareza para pequenas empresas.',
            'Público de pequenas empresas',
            'Solicitar um orçamento de teste',
            $revision,
        );
    }

    private function candidate(
        string $tenant='a',
        int $contentRevision=1,
        ?string $headline=null,
        int $briefRevision=0,
    ): SiteContentCandidate {
        return new SiteContentCandidate(
            $this->brief($tenant,$briefRevision),
            $contentRevision,
            $headline ?? 'Uma página clara para seu negócio',
            'Descrição de exemplo que explica serviços e benefícios, sem dados reais ou identificação de clientes.',
            'Solicitar uma proposta inicial',
        );
    }

    private function command(
        SiteContentCandidate $candidate,
        string $id='DEMO-REVIEW-CMD-001',
        SiteReviewDecision $decision=SiteReviewDecision::Approve,
        int $revision=0,
        ?string $hash=null,
        ?string $tenant=null,
        ?string $actor=null,
        ?string $reference=null,
    ): SiteReviewCommand {
        $tenant ??= $candidate->brief->organizationId->value;
        return new SiteReviewCommand(
            $id,
            new OrganizationId($tenant),
            $reference ?? $candidate->brief->reference,
            $hash ?? $candidate->fingerprint,
            $actor ?? (
                $tenant==='org-a' ? 'DEMO-REVIEWER-A' : 'DEMO-REVIEWER-B'
            ),
            $revision,
            $decision,
            1800000030,
        );
    }

    public function test_matching_approval_creates_documentary_fake_event_but_cannot_publish(): void
    {
        $candidate=$this->candidate();
        $journal=new DemoSiteReviewJournal($candidate);
        self::assertSame('awaiting_review_demo',$journal->currentSimulatedStatus());
        self::assertSame(0,$journal->currentReviewRevision());
        self::assertFalse($journal->hasVerifiedCustomerIdentity());

        $result=$journal->apply($this->command($candidate));
        self::assertSame('approved_in_memory',$result->reason);
        self::assertSame('approved_demo',$result->simulatedStatus);
        self::assertSame(1,$result->reviewRevision);
        self::assertTrue($result->isSimulatedApproval());
        self::assertSame('approved_demo',$journal->currentSimulatedStatus());
        self::assertCount(1,$journal->syntheticHistory());
        self::assertSame($candidate->fingerprint,
            $journal->syntheticHistory()[0]['content_sha256']);
        self::assertSame($candidate->brief->reference,
            $journal->syntheticHistory()[0]['reference']);
        self::assertFalse($journal->canPublishSite());
        self::assertFalse($journal->isSignedApproval());
        self::assertFalse($result->mayPublishSite());
        self::assertFalse($result->mayActivateContract());
        self::assertFalse($result->isVerifiedClientConsent());
        self::assertFalse($candidate->canPublish());
        self::assertFalse($candidate->canCharge());
    }

    public function test_exact_review_retry_is_idempotent_and_does_not_append_a_second_event(): void
    {
        $candidate=$this->candidate();
        $journal=new DemoSiteReviewJournal($candidate);
        $command=$this->command($candidate);
        self::assertSame('approved_in_memory',$journal->apply($command)->reason);
        $repeat=$journal->apply($command);
        self::assertSame('replayed_in_memory',$repeat->reason);
        self::assertSame('approved_demo',$repeat->simulatedStatus);
        self::assertTrue($repeat->isSimulatedApproval());
        self::assertSame(1,$journal->currentReviewRevision());
        self::assertCount(1,$journal->syntheticHistory());
        self::assertFalse($repeat->mayPublishSite());
    }

    public function test_same_command_id_with_different_decision_cannot_overwrite_original_review(): void
    {
        $candidate=$this->candidate();
        $journal=new DemoSiteReviewJournal($candidate);
        self::assertSame('approved_in_memory',$journal->apply(
            $this->command($candidate),
        )->reason);
        $changed=$journal->apply($this->command(
            $candidate,decision:SiteReviewDecision::Reject,
        ));
        self::assertSame('idempotency_conflict',$changed->reason);
        self::assertSame('approved_demo',$journal->currentSimulatedStatus());
        self::assertCount(1,$journal->syntheticHistory());
    }

    public function test_new_content_text_or_revision_has_a_different_hash_and_no_inherited_approval(): void
    {
        $original=$this->candidate();
        $old=new DemoSiteReviewJournal($original);
        $oldCommand=$this->command($original);
        self::assertSame('approved_in_memory',$old->apply($oldCommand)->reason);

        $edited=$this->candidate(
            contentRevision:2,
            headline:'Conteúdo ajustado após a primeira análise',
        );
        self::assertNotSame($original->fingerprint,$edited->fingerprint);
        self::assertSame(64,strlen($edited->fingerprint));
        $new=new DemoSiteReviewJournal($edited);
        self::assertSame('awaiting_review_demo',$new->currentSimulatedStatus());
        self::assertSame(0,$new->currentReviewRevision());
        self::assertSame('content_changed',$new->apply($oldCommand)->reason);
        self::assertCount(0,$new->syntheticHistory());
        self::assertSame('approved_in_memory',$new->apply(
            $this->command($edited,id:'DEMO-REVIEW-CMD-002'),
        )->reason);
        self::assertCount(1,$new->syntheticHistory());
        self::assertFalse($new->canPublishSite());
    }

    public function test_even_identical_copy_requires_new_review_when_brief_revision_changes(): void
    {
        $old=$this->candidate(briefRevision:1);
        $editedBrief=$this->candidate(briefRevision:2);
        self::assertNotSame($old->fingerprint,$editedBrief->fingerprint);
        $journal=new DemoSiteReviewJournal($editedBrief);
        self::assertSame('content_changed',$journal->apply($this->command($old))->reason);
        self::assertSame('awaiting_review_demo',$journal->currentSimulatedStatus());
        self::assertFalse($journal->isSignedApproval());
    }

    public function test_requesting_changes_and_rejection_are_terminal_for_that_exact_candidate(): void
    {
        foreach([
            [SiteReviewDecision::RequestChanges,'changes_requested_in_memory','changes_requested_demo'],
            [SiteReviewDecision::Reject,'rejected_in_memory','rejected_demo'],
        ] as [$decision,$reason,$status]){
            $candidate=$this->candidate();
            $journal=new DemoSiteReviewJournal($candidate);
            $first=$journal->apply($this->command($candidate,decision:$decision));
            self::assertSame($reason,$first->reason);
            self::assertSame($status,$first->simulatedStatus);
            self::assertFalse($first->isSimulatedApproval());
            self::assertFalse($first->mayPublishSite());
            $second=$journal->apply($this->command(
                $candidate,id:'DEMO-REVIEW-CMD-002',
                decision:SiteReviewDecision::Approve,revision:1,
            ));
            self::assertSame('review_already_completed',$second->reason);
            self::assertSame(1,$journal->currentReviewRevision());
            self::assertCount(1,$journal->syntheticHistory());
        }
    }

    public function test_stale_review_revision_does_not_consume_command_id(): void
    {
        $candidate=$this->candidate();
        $journal=new DemoSiteReviewJournal($candidate);
        self::assertSame('stale_review_revision',$journal->apply(
            $this->command($candidate,revision:4),
        )->reason);
        self::assertSame(0,$journal->currentReviewRevision());
        self::assertSame([],$journal->syntheticHistory());
        self::assertSame('approved_in_memory',$journal->apply(
            $this->command($candidate),
        )->reason);
    }

    public function test_cross_tenant_reference_content_and_demo_actor_are_rejected_before_idempotency(): void
    {
        $candidate=$this->candidate();
        $journal=new DemoSiteReviewJournal($candidate);
        $base=$this->command($candidate);
        self::assertSame('approved_in_memory',$journal->apply($base)->reason);

        self::assertSame('organization_mismatch',$journal->apply(
            $this->command($candidate,tenant:'org-b'),
        )->reason);
        self::assertSame('reference_mismatch',$journal->apply(
            $this->command($candidate,reference:'DEMO-SITE-REQ-B1'),
        )->reason);
        self::assertSame('content_changed',$journal->apply(
            $this->command($candidate,hash:str_repeat('0',64)),
        )->reason);
        self::assertSame('untrusted_demo_actor',$journal->apply(
            $this->command($candidate,actor:'DEMO-REVIEWER-B'),
        )->reason);
        self::assertSame(1,$journal->currentReviewRevision());
        self::assertCount(1,$journal->syntheticHistory());
    }

    public function test_organization_b_can_review_only_its_own_fictional_candidate(): void
    {
        $candidate=$this->candidate('b');
        $journal=new DemoSiteReviewJournal($candidate);
        $done=$journal->apply($this->command($candidate));
        self::assertSame('approved_in_memory',$done->reason);
        self::assertSame('org-b',$journal->syntheticHistory()[0]['organization']);
        self::assertSame('DEMO-REVIEWER-B',$journal->syntheticHistory()[0]['actor']);
        self::assertFalse($done->isVerifiedClientConsent());
    }

    public function test_copy_rejects_html_bidi_controls_invalid_utf8_and_length_violations(): void
    {
        foreach([
            'Título <script>injetado</script> com HTML',
            "Título \u{202E} com caracteres direcionais",
            "Título de teste com UTF-8 inválido \xC3\x28",
            'Curto',
            str_repeat('a',121),
        ] as $headline) {
            try {
                $this->candidate(headline:$headline);
                self::fail('Invalid synthetic copy headline accepted.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        $this->candidate(contentRevision:0);
    }

    public function test_content_fingerprint_normalizes_whitespace_deterministically(): void
    {
        $first=$this->candidate();
        $second=$this->candidate(headline:'  Uma página  clara   para seu negócio ');
        self::assertSame($first->headline,$second->headline);
        self::assertSame($first->fingerprint,$second->fingerprint);
        self::assertSame($first->description,$second->description);
        self::assertTrue((new \ReflectionClass(SiteContentCandidate::class))->isReadOnly());
        self::assertTrue((new \ReflectionClass(SiteReviewCommand::class))->isReadOnly());
        self::assertTrue((new \ReflectionClass(SiteReviewOutcome::class))->isReadOnly());
    }

    public function test_invalid_review_command_cannot_claim_external_approval(): void
    {
        $candidate=$this->candidate();
        foreach([
            ['id'=>'REAL-COMMAND'],
            ['id'=>'DEMO-REVIEW-CMD-'],
            ['hash'=>'not-a-hash'],
            ['actor'=>'real_user_id'],
            ['revision'=>-1],
            ['revision'=>100001],
        ] as $override){
            try {
                new SiteReviewCommand(
                    $override['id'] ?? 'DEMO-REVIEW-CMD-001',
                    $candidate->brief->organizationId,
                    $candidate->brief->reference,
                    $override['hash'] ?? $candidate->fingerprint,
                    $override['actor'] ?? 'DEMO-REVIEWER-A',
                    $override['revision'] ?? 0,
                    SiteReviewDecision::Approve,1800000030,
                );
                self::fail('Untrusted review command accepted.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        new SiteReviewOutcome('signed_customer_approval',null,null);
    }
}
