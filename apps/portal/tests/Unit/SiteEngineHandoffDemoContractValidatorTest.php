<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Organizations\OrganizationId;
use App\Domain\ServicePortfolio\PortfolioProduct;
use App\Domain\SiteIntake\DemoSiteReviewJournal;
use App\Domain\SiteIntake\SiteBriefDraft;
use App\Domain\SiteIntake\SiteContentCandidate;
use App\Domain\SiteIntake\SiteEngineHandoffDemoContractValidator;
use App\Domain\SiteIntake\SiteEngineHandoffDemoValidationResult;
use App\Domain\SiteIntake\SiteEngineHandoffPreviewBuilder;
use App\Domain\SiteIntake\SiteReviewCommand;
use App\Domain\SiteIntake\SiteReviewDecision;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SiteEngineHandoffDemoContractValidatorTest extends TestCase
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
                'Apresentar serviços digitais numa página fictícia de exemplo.',
                'Empresas locais em crescimento',
                'Solicitar uma demonstração',
                $briefRevision,
            ),
            $contentRevision,
            'Uma presença digital simples para seu negócio',
            'Descrição de exemplo para demonstrar visualização e revisão editorial de um site fictício.',
            'Conhecer mais detalhes',
        );
    }

    private function journal(
        SiteContentCandidate $candidate,
        bool $approved=true,
    ): DemoSiteReviewJournal {
        $journal=new DemoSiteReviewJournal($candidate);
        if ($approved) {
            $tenant=$candidate->brief->organizationId->value;
            self::assertTrue($journal->apply(new SiteReviewCommand(
                'DEMO-REVIEW-CMD-001',
                $candidate->brief->organizationId,
                $candidate->brief->reference,
                $candidate->fingerprint,
                $tenant==='org-a'?'DEMO-REVIEWER-A':'DEMO-REVIEWER-B',
                0,SiteReviewDecision::Approve,1800000030,
            ))->isSimulatedApproval());
        }
        return $journal;
    }

    /** @return array{0:SiteContentCandidate,1:DemoSiteReviewJournal,2:string} */
    private function sample(): array
    {
        $candidate=$this->candidate();
        $journal=$this->journal($candidate);
        $result=(new SiteEngineHandoffPreviewBuilder())->prepare($candidate,$journal);
        self::assertTrue($result->hasPreviewEnvelope());
        return [$candidate,$journal,$result->envelope->canonicalJson()];
    }

    private function inspect(
        string $json,SiteContentCandidate $candidate,DemoSiteReviewJournal $journal
    ): SiteEngineHandoffDemoValidationResult {
        return (new SiteEngineHandoffDemoContractValidator())->inspect($json,$candidate,$journal);
    }

    public function test_exact_canonical_fixture_is_matching_demo_only_not_external_authorization(): void
    {
        [$candidate,$journal,$json]=$this->sample();
        $result=$this->inspect($json,$candidate,$journal);
        self::assertSame('matching_demo_preview',$result->reason);
        self::assertTrue($result->matchesSyntheticContract());
        self::assertSame(64,strlen($result->canonicalFingerprint));
        self::assertSame(hash('sha256',$json),$result->canonicalFingerprint);
        self::assertFalse($result->isAuthenticatedTransport());
        self::assertFalse($result->isVerifiedCustomerApproval());
        self::assertFalse($result->permitsSitePublishing());
        self::assertTrue((new \ReflectionClass(SiteEngineHandoffDemoValidationResult::class))->isReadOnly());
    }

    public function test_pending_local_review_refuses_well_formed_bytes_from_unverified_external_actor(): void
    {
        [$candidate,$journal,$json]=$this->sample();
        $pending=$this->journal($candidate,false);
        $denied=$this->inspect($json,$candidate,$pending);
        self::assertSame('local_review_unavailable',$denied->reason);
        self::assertFalse($denied->matchesSyntheticContract());
        self::assertNull($denied->canonicalFingerprint);
        self::assertFalse($denied->permitsSitePublishing());
    }

    public function test_changed_content_or_brief_never_matches_old_handoff_even_with_same_bytes(): void
    {
        [$candidate,$journal,$json]=$this->sample();
        foreach ([
            $this->candidate(contentRevision:2),
            $this->candidate(briefRevision:1),
            $this->candidate(product:PortfolioProduct::LandingPage),
            $this->candidate('b'),
        ] as $current) {
            $denied=$this->inspect($json,$current,$journal);
            self::assertSame('local_review_unavailable',$denied->reason);
            self::assertNull($denied->canonicalFingerprint);
        }
    }

    public function test_wrong_tenant_or_site_or_product_in_payload_is_rejected_even_if_valid_json(): void
    {
        [$candidate,$journal,$json]=$this->sample();
        foreach ([
            ['organization_demo','org-b'],
            ['site_reference_demo','DEMO-SITE-REQ-B1'],
            ['product','sites_landing_page'],
            ['content_sha256_demo',str_repeat('0',64)],
            ['review_command_demo','DEMO-REVIEW-CMD-OTHER'],
            ['review_status','rejected_demo'],
            ['schema','imagine.portal.sites-handoff-preview.v2'],
        ] as [$field,$changed]) {
            $obj=json_decode($json,true,8,JSON_THROW_ON_ERROR);
            $obj[$field]=$changed;
            $mutated=json_encode($obj,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            self::assertSame('noncanonical_or_mismatch',$this->inspect(
                $mutated,$candidate,$journal
            )->reason);
        }
    }

    public function test_new_revision_even_with_same_content_hash_is_not_accepted(): void
    {
        [$candidate,$journal,$json]=$this->sample();
        foreach ([
            ['brief_revision',99],
            ['content_revision',99],
            ['review_revision',0],
        ] as [$field,$value]) {
            $obj=json_decode($json,true,8,JSON_THROW_ON_ERROR);
            $obj[$field]=$value;
            self::assertSame('noncanonical_or_mismatch',$this->inspect(
                json_encode($obj,JSON_THROW_ON_ERROR),$candidate,$journal
            )->reason);
        }
    }

    public function test_authorization_flags_must_remain_boolean_false_and_cannot_be_promoted(): void
    {
        [$candidate,$journal,$json]=$this->sample();
        foreach ([
            'authenticated_customer_approval',
            'external_delivery_enabled',
            'publishing_enabled',
        ] as $field) {
            $obj=json_decode($json,true,8,JSON_THROW_ON_ERROR);
            $obj[$field]=true;
            self::assertSame('noncanonical_or_mismatch',$this->inspect(
                json_encode($obj,JSON_THROW_ON_ERROR),$candidate,$journal
            )->reason);
            $obj[$field]='false';
            self::assertSame('noncanonical_or_mismatch',$this->inspect(
                json_encode($obj,JSON_THROW_ON_ERROR),$candidate,$journal
            )->reason);
        }
    }

    public function test_unknown_key_duplicated_key_whitespace_and_reordered_fields_are_rejected(): void
    {
        [$candidate,$journal,$json]=$this->sample();
        $obj=json_decode($json,true,8,JSON_THROW_ON_ERROR);
        $extra=$obj;
        $extra['email']='visitor@example.invalid';
        $reversed=array_reverse($obj,true);
        $duplicate=substr($json,0,-1).',"publishing_enabled":false}';
        foreach ([
            json_encode($extra,JSON_THROW_ON_ERROR),
            json_encode($reversed,JSON_THROW_ON_ERROR),
            $duplicate,
            "  ".$json,
            $json."\n",
        ] as $modified) {
            self::assertSame('noncanonical_or_mismatch',
                $this->inspect($modified,$candidate,$journal)->reason);
        }
    }

    public function test_invalid_or_multiple_json_documents_are_rejected_before_review_checks(): void
    {
        [$candidate,$journal,$json]=$this->sample();
        foreach ([
            '{invalid',
            '{}{}',
            '[1,2,3]',
            '"string"',
            'true',
            "header\xC3\x28",
        ] as $invalid) {
            $out=$this->inspect($invalid,$candidate,$journal);
            self::assertSame('malformed_json',$out->reason);
            self::assertFalse($out->isAuthenticatedTransport());
        }
        // Two complete canonical envelopes exceed the explicit 1024-byte
        // safety cap and are denied BEFORE JSON parsing.
        self::assertSame('payload_too_large',
            $this->inspect($json.$json,$candidate,$journal)->reason);
    }

    public function test_oversized_payload_or_empty_string_is_denied_before_any_envelope(): void
    {
        [$candidate,$journal,$json]=$this->sample();
        foreach (['',str_repeat('z',1025)] as $bad) {
            $result=$this->inspect($bad,$candidate,$journal);
            self::assertSame('payload_too_large',$result->reason);
            self::assertNull($result->canonicalFingerprint);
        }
    }

    public function test_matching_preview_is_idempotent_across_repeated_local_verifications(): void
    {
        [$candidate,$journal,$json]=$this->sample();
        $validator=new SiteEngineHandoffDemoContractValidator();
        $first=$validator->inspect($json,$candidate,$journal);
        $second=$validator->inspect($json,$candidate,$journal);
        self::assertSame($first->reason,$second->reason);
        self::assertSame($first->canonicalFingerprint,$second->canonicalFingerprint);
        self::assertCount(1,$journal->syntheticHistory());
        self::assertSame(1,$journal->currentReviewRevision());
    }

    public function test_result_rejects_forged_live_permission_or_non_sha_fingerprint(): void
    {
        foreach ([
            ['authorized_for_production',null],
            ['matching_demo_preview','abcd'],
            ['payload_too_large',str_repeat('a',64)],
        ] as [$reason,$digest]) {
            try {
                new SiteEngineHandoffDemoValidationResult($reason,$digest);
                self::fail('Forged result accepted.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertFalse(
            SiteEngineHandoffDemoValidationResult::denied('malformed_json')
                ->isVerifiedCustomerApproval(),
        );
    }
}
