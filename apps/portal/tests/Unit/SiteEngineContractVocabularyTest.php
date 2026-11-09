<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Organizations\OrganizationId;
use App\Domain\ServicePortfolio\PortfolioProduct;
use App\Domain\SiteIntake\DemoSiteReviewJournal;
use App\Domain\SiteIntake\SiteBriefDraft;
use App\Domain\SiteIntake\SiteContentCandidate;
use App\Domain\SiteIntake\SiteEngineContractVocabulary as Vocabulary;
use App\Domain\SiteIntake\SiteEngineHandoffDemoContractValidator;
use App\Domain\SiteIntake\SiteEngineHandoffPreviewBuilder;
use App\Domain\SiteIntake\SiteReviewCommand;
use App\Domain\SiteIntake\SiteReviewDecision;
use PHPUnit\Framework\TestCase;

/**
 * F50: uses a reviewed snapshot of imagine-sites candidate schemas.
 * These are TEST-ONLY schema names, not operational transport/authority.
 */
final class SiteEngineContractVocabularyTest extends TestCase
{
    public function test_onepage_and_landing_page_are_explicitly_mapped_to_existing_sites_candidate_product_codes(): void
    {
        self::assertSame('onepage',Vocabulary::createProjectProductCode(PortfolioProduct::Onepage));
        self::assertSame('landing_page',Vocabulary::createProjectProductCode(PortfolioProduct::LandingPage));
        self::assertNotSame(PortfolioProduct::Onepage->value,'onepage');
        self::assertNotSame(PortfolioProduct::LandingPage->value,'landing_page');
        self::assertSame(PortfolioProduct::Onepage,Vocabulary::productFromCreateProjectCode('onepage'));
        self::assertSame(PortfolioProduct::LandingPage,Vocabulary::productFromCreateProjectCode('landing_page'));
    }

    public function test_other_portal_products_and_noncanonical_codes_cannot_be_sneaked_into_sites_project_creation(): void
    {
        foreach ([
            PortfolioProduct::PerformanceAI,
            PortfolioProduct::ImagineCRM,
            PortfolioProduct::UniversidadeImagine,
        ] as $product) {
            self::assertNull(Vocabulary::createProjectProductCode($product));
        }
        foreach (['sites_onepage','sites_landing_page','imagine_crm',
            'universidade_imagine','performance_ai','onePage',
            'landing-page','LANDING_PAGE',''] as $invalid) {
            self::assertNull(Vocabulary::productFromCreateProjectCode($invalid));
        }
    }

    public function test_contract_directions_are_distinct_and_local_preview_is_not_a_transport_message(): void
    {
        self::assertSame(Vocabulary::PORTAL_TO_SITES,
            Vocabulary::candidateDirection('create_project_v1'));
        self::assertSame(Vocabulary::SITES_TO_PORTAL,
            Vocabulary::candidateDirection('review_handoff_epoch_v2'));
        self::assertSame(Vocabulary::INTERNAL_ONLY,
            Vocabulary::candidateDirection('portal_local_preview_v1'));
        self::assertNull(Vocabulary::candidateDirection('register_client_acceptance'));
        self::assertNull(Vocabulary::candidateDirection('request_publication'));
        self::assertNull(Vocabulary::candidateDirection('any_real_webhook'));
    }

    public function test_create_project_and_review_proposal_use_exact_distinct_schema_keys(): void
    {
        self::assertSame([
            'schemaVersion','operationId','tenantRef','orderRef',
            'contractRef','contractRevision','productCode','idempotencyKey',
        ],Vocabulary::createProjectV1Keys());
        self::assertSame([
            'schemaVersion','issuer','audience','issuedAt','expiresAt',
            'nonce','authorizationRevision','candidate','mac',
        ],Vocabulary::reviewProposalV2Keys());
        self::assertSame(8,count(Vocabulary::createProjectV1Keys()));
        self::assertSame(9,count(Vocabulary::reviewProposalV2Keys()));
        self::assertSame('1',Vocabulary::SITES_CREATE_PROJECT_V1);
        self::assertSame('sites.synthetic.review-handoff.v2',Vocabulary::SITES_REVIEW_PROPOSAL_V2);
    }

    public function test_f48_preview_cannot_be_misrepresented_as_create_project_or_signed_review(): void
    {
        $previewKeys=[
            'schema','kind','source','target','organization_demo',
            'site_reference_demo','product','brief_revision','content_revision',
            'content_sha256_demo','review_command_demo','review_revision',
            'review_status','authenticated_customer_approval',
            'external_delivery_enabled','publishing_enabled',
        ];
        self::assertNotSame($previewKeys,Vocabulary::createProjectV1Keys());
        self::assertNotSame($previewKeys,Vocabulary::reviewProposalV2Keys());
        self::assertNotContains('schemaVersion',$previewKeys);
        self::assertNotContains('idempotencyKey',$previewKeys);
        self::assertNotContains('mac',$previewKeys);
        self::assertNotContains('authorizationRevision',$previewKeys);
        self::assertSame('imagine.portal.sites-handoff-preview.v1',Vocabulary::PORTAL_LOCAL_PREVIEW);
    }

    public function test_candidate_messages_are_rejected_by_f49_preview_byte_validator_even_with_matching_fake_review(): void
    {
        $candidate=new SiteContentCandidate(
            new SiteBriefDraft(
                'DEMO-SITE-REQ-A1',new OrganizationId('org-a'),
                PortfolioProduct::Onepage,
                'Explicar serviços de uma empresa fictícia no Portal.',
                'Pequenas empresas brasileiras',
                'Solicitar contato de exemplo',0,
            ),
            1,'Uma página para negócios locais',
            'Conteúdo de exemplo para demonstrar a revisão interna sem encaminhar ou publicar um site.',
            'Solicitar proposta',
        );
        $journal=new DemoSiteReviewJournal($candidate);
        $review=$journal->apply(new SiteReviewCommand(
            'DEMO-REVIEW-CMD-001',new OrganizationId('org-a'),
            $candidate->brief->reference,$candidate->fingerprint,
            'DEMO-REVIEWER-A',0,SiteReviewDecision::Approve,1800000030,
        ));
        self::assertTrue($review->isSimulatedApproval());
        $validator=new SiteEngineHandoffDemoContractValidator();

        $create=[
            'schemaVersion'=>'1','operationId'=>'DEMO-OP-001',
            'tenantRef'=>'org-a','orderRef'=>'DEMO-ORDER-001',
            'contractRef'=>'DEMO-CONTRACT-001','contractRevision'=>1,
            'productCode'=>'onepage','idempotencyKey'=>'DEMO-OP-001',
        ];
        $reviewCandidate=[
            'schemaVersion'=>'sites.synthetic.review-candidate.v1',
            'tenantRef'=>'org-a','siteRef'=>'DEMO-SITE-REQ-A1',
            'workflowRef'=>'DEMO-WF-001','workflowRevision'=>1,
            'releaseRef'=>'DEMO-RELEASE-001',
            'artifactDigest'=>str_repeat('a',64),
            'portalAuthorityRequired'=>true,
            'officialClientAcceptance'=>false,
            'publishable'=>false,
        ];
        $reviewWire=[
            'schemaVersion'=>Vocabulary::SITES_REVIEW_PROPOSAL_V2,
            'issuer'=>'imagine-sites','audience'=>'imagine-portal',
            'issuedAt'=>1800000000,'expiresAt'=>1800000120,
            'nonce'=>'DEMO-NONCE-001','authorizationRevision'=>1,
            'candidate'=>$reviewCandidate,'mac'=>str_repeat('a',64),
        ];

        foreach ([$create,$reviewWire] as $differentProtocol) {
            $payload=json_encode($differentProtocol,JSON_THROW_ON_ERROR);
            $result=$validator->inspect($payload,$candidate,$journal);
            self::assertSame('noncanonical_or_mismatch',$result->reason);
            self::assertFalse($result->isAuthenticatedTransport());
            self::assertFalse($result->permitsSitePublishing());
        }

        $preview=(new SiteEngineHandoffPreviewBuilder())->prepare($candidate,$journal);
        self::assertTrue($preview->hasPreviewEnvelope());
        self::assertSame('matching_demo_preview',$validator->inspect(
            $preview->envelope->canonicalJson(),$candidate,$journal
        )->reason);
        self::assertFalse($preview->isTransportAuthorized());
    }

    public function test_no_candidate_vocabulary_grants_approval_publication_or_motor_execution(): void
    {
        self::assertFalse(Vocabulary::mayInvokeOperationalSitesEngine());
        self::assertFalse(Vocabulary::mayAcceptCustomerApproval());
        self::assertFalse(Vocabulary::mayRequestPublication());
    }
}
