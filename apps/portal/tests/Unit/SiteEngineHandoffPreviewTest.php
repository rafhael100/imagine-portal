<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Organizations\OrganizationId;
use App\Domain\ServicePortfolio\PortfolioProduct;
use App\Domain\SiteIntake\DemoSiteReviewJournal;
use App\Domain\SiteIntake\SiteBriefDraft;
use App\Domain\SiteIntake\SiteContentCandidate;
use App\Domain\SiteIntake\SiteEngineHandoffPreviewBuilder;
use App\Domain\SiteIntake\SiteEngineHandoffPreviewEnvelope;
use App\Domain\SiteIntake\SiteEngineHandoffPreviewResult;
use App\Domain\SiteIntake\SiteReviewCommand;
use App\Domain\SiteIntake\SiteReviewDecision;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SiteEngineHandoffPreviewTest extends TestCase
{
    private function candidate(
        string $tenant='a',
        int $briefRevision=0,
        int $contentRevision=1,
        PortfolioProduct $product=PortfolioProduct::Onepage,
    ): SiteContentCandidate {
        return new SiteContentCandidate(
            new SiteBriefDraft(
                'DEMO-SITE-REQ-'.strtoupper($tenant).'1',
                new OrganizationId('org-'.$tenant),
                $product,
                'Criar uma página institucional com serviços ilustrativos.',
                'Pequenas empresas locais',
                'Solicitar informações iniciais',
                $briefRevision,
            ),
            $contentRevision,
            'Uma apresentação para sua empresa',
            'Conteúdo inteiramente fictício para explicar benefícios comerciais e serviços de uma empresa.',
            'Solicitar uma proposta',
        );
    }

    private function journal(
        SiteContentCandidate $candidate,
        ?SiteReviewDecision $decision=SiteReviewDecision::Approve,
    ): DemoSiteReviewJournal {
        $journal=new DemoSiteReviewJournal($candidate);
        if ($decision!==null) {
            $tenant=$candidate->brief->organizationId->value;
            $result=$journal->apply(new SiteReviewCommand(
                'DEMO-REVIEW-CMD-001',
                $candidate->brief->organizationId,
                $candidate->brief->reference,
                $candidate->fingerprint,
                $tenant==='org-a'?'DEMO-REVIEWER-A':'DEMO-REVIEWER-B',
                0,$decision,1800000030,
            ));
            self::assertSame(1,$result->reviewRevision);
        }
        return $journal;
    }

    public function test_approved_demo_generates_stable_metadata_only_envelope_but_no_delivery_permission(): void
    {
        $candidate=$this->candidate();
        $journal=$this->journal($candidate);
        $result=(new SiteEngineHandoffPreviewBuilder())->prepare($candidate,$journal);
        self::assertSame('preview_ready',$result->reason);
        self::assertTrue($result->hasPreviewEnvelope());
        self::assertInstanceOf(SiteEngineHandoffPreviewEnvelope::class,$result->envelope);
        $fields=$result->envelope->fields();
        self::assertCount(16,$fields);
        self::assertSame('imagine.portal.sites-handoff-preview.v1',$fields['schema']);
        self::assertSame('imagine-portal',$fields['source']);
        self::assertSame('imagine-sites-engine',$fields['target']);
        self::assertSame('org-a',$fields['organization_demo']);
        self::assertSame('DEMO-SITE-REQ-A1',$fields['site_reference_demo']);
        self::assertSame('sites_onepage',$fields['product']);
        self::assertSame($candidate->fingerprint,$fields['content_sha256_demo']);
        self::assertSame('DEMO-REVIEW-CMD-001',$fields['review_command_demo']);
        self::assertSame('approved_demo',$fields['review_status']);
        self::assertFalse($fields['authenticated_customer_approval']);
        self::assertFalse($fields['external_delivery_enabled']);
        self::assertFalse($fields['publishing_enabled']);
        self::assertSame($fields,json_decode($result->envelope->canonicalJson(),true,512,JSON_THROW_ON_ERROR));
        self::assertSame(64,strlen($result->envelope->fingerprint()));
        self::assertFalse($result->envelope->maySendToSitesEngine());
        self::assertFalse($result->envelope->mayPublishSite());
        self::assertFalse($result->envelope->isVerifiedClientConsent());
        self::assertFalse($result->isTransportAuthorized());
        self::assertFalse($result->isCustomerApprovalVerified());
        self::assertFalse($result->mayPublishSite());
    }

    public function test_repeated_preview_is_canonical_and_does_not_modify_review_journal(): void
    {
        $candidate=$this->candidate();
        $journal=$this->journal($candidate);
        $builder=new SiteEngineHandoffPreviewBuilder();
        $first=$builder->prepare($candidate,$journal);
        $second=$builder->prepare($candidate,$journal);
        self::assertSame($first->envelope->canonicalJson(),$second->envelope->canonicalJson());
        self::assertSame($first->envelope->fingerprint(),$second->envelope->fingerprint());
        self::assertSame(1,$journal->currentReviewRevision());
        self::assertCount(1,$journal->syntheticHistory());
        self::assertFalse($second->isTransportAuthorized());
    }

    public function test_pending_review_produces_no_handoff_preview(): void
    {
        $candidate=$this->candidate();
        $result=(new SiteEngineHandoffPreviewBuilder())->prepare(
            $candidate,$this->journal($candidate,null),
        );
        self::assertSame('no_demo_review',$result->reason);
        self::assertFalse($result->hasPreviewEnvelope());
        self::assertNull($result->envelope);
        self::assertFalse($result->isTransportAuthorized());
    }

    public function test_change_request_and_rejection_never_produce_approved_envelope(): void
    {
        foreach ([SiteReviewDecision::Reject,SiteReviewDecision::RequestChanges] as $decision) {
            $candidate=$this->candidate();
            $result=(new SiteEngineHandoffPreviewBuilder())->prepare(
                $candidate,$this->journal($candidate,$decision),
            );
            self::assertSame('not_approved_demo',$result->reason);
            self::assertNull($result->envelope);
            self::assertFalse($result->mayPublishSite());
        }
    }

    public function test_content_revision_changed_after_original_fake_review_blocks_old_envelope(): void
    {
        $old=$this->candidate(contentRevision:1);
        $journal=$this->journal($old);
        $new=$this->candidate(contentRevision:2);
        self::assertNotSame($old->fingerprint,$new->fingerprint);
        $result=(new SiteEngineHandoffPreviewBuilder())->prepare($new,$journal);
        self::assertSame('content_mismatch',$result->reason);
        self::assertNull($result->envelope);
    }

    public function test_brief_revision_product_change_or_other_tenant_cannot_reuse_approval(): void
    {
        $old=$this->candidate();
        $journal=$this->journal($old);
        foreach ([
            $this->candidate(briefRevision:1),
            $this->candidate(product:PortfolioProduct::LandingPage),
            $this->candidate('b'),
        ] as $changed) {
            $result=(new SiteEngineHandoffPreviewBuilder())->prepare($changed,$journal);
            self::assertSame('content_mismatch',$result->reason);
            self::assertFalse($result->hasPreviewEnvelope());
        }
    }

    public function test_tenant_b_landing_page_has_different_explicit_metadata_and_no_live_authority(): void
    {
        $candidate=$this->candidate('b',product:PortfolioProduct::LandingPage);
        $result=(new SiteEngineHandoffPreviewBuilder())->prepare(
            $candidate,$this->journal($candidate),
        );
        self::assertTrue($result->hasPreviewEnvelope());
        self::assertSame('org-b',$result->envelope->fields()['organization_demo']);
        self::assertSame('sites_landing_page',$result->envelope->fields()['product']);
        self::assertFalse($result->envelope->maySendToSitesEngine());
        self::assertFalse($result->envelope->mayPublishSite());
    }

    public function test_handoff_metadata_never_contains_free_text_contacts_or_sensitive_briefing_fields(): void
    {
        $candidate=$this->candidate();
        $result=(new SiteEngineHandoffPreviewBuilder())->prepare(
            $candidate,$this->journal($candidate),
        );
        $json=$result->envelope->canonicalJson();
        foreach ([
            'Criar uma página institucional',
            'Pequenas empresas',
            'Solicitar informações',
            $candidate->headline,
            $candidate->description,
            $candidate->callToAction,
            'email','phone','password','cnpj',
            'contact','payment_token','bearer','authorization',
        ] as $text) {
            self::assertStringNotContainsString($text,$json);
        }
        self::assertLessThan(1000,strlen($json));
    }

    public function test_only_closed_demo_identifiers_and_valid_product_can_construct_an_envelope(): void
    {
        $base=[
            'org-a','DEMO-SITE-REQ-A1','sites_onepage',0,1,
            str_repeat('a',64),'DEMO-REVIEW-CMD-001',1,
        ];
        foreach ([
            [0,'org-c'],
            [1,'SITE-REAL-123'],
            [2,'imagine_crm'],
            [3,-1],
            [4,0],
            [5,'not-a-sha256'],
            [6,'REAL-SIGNED-APPROVAL'],
            [7,0],
        ] as [$index,$bad]) {
            $args=$base;
            $args[$index]=$bad;
            try {
                new SiteEngineHandoffPreviewEnvelope(...$args);
                self::fail('Invalid fake handoff envelope accepted');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_results_reject_false_claims_of_live_delivery_or_client_approval(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SiteEngineHandoffPreviewResult('customer_approved_and_published',null);
    }

    public function test_metadata_and_result_are_readonly(): void
    {
        self::assertTrue((new \ReflectionClass(SiteEngineHandoffPreviewEnvelope::class))->isReadOnly());
        self::assertTrue((new \ReflectionClass(SiteEngineHandoffPreviewResult::class))->isReadOnly());
        self::assertSame('no_demo_review',SiteEngineHandoffPreviewResult::blocked('no_demo_review')->reason);
    }
}
