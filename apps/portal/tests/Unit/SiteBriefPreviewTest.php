<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Organizations\OrganizationId;
use App\Domain\ServicePortfolio\PortfolioProduct;
use App\Domain\SiteIntake\SiteBriefDraft;
use App\Domain\SiteIntake\SiteBriefPreview;
use App\Domain\SiteIntake\SiteBriefPreviewResult;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SiteBriefPreviewTest extends TestCase
{
    /** @return array<string,mixed> */
    private function input(): array
    {
        return [
            'reference'=>'DEMO-SITE-REQ-A1',
            'organization'=>'org-a',
            'product'=>PortfolioProduct::Onepage->value,
            'objective'=>'Apresentar nossos serviços com clareza para novos visitantes.',
            'audience'=>'Pequenas empresas locais',
            'call_to_action'=>'Solicitar um orçamento inicial',
            'revision'=>0,
        ];
    }

    private function preview(array $payload, string $tenant='org-a'): SiteBriefPreviewResult
    {
        return (new SiteBriefPreview())->evaluate($payload,new OrganizationId($tenant));
    }

    public function test_onepage_brief_has_normalized_fields_but_cannot_submit_publish_or_charge(): void
    {
        $input=$this->input();
        $input['objective']='  Apresentar    nossos serviços com clareza para novos visitantes.  ';
        $result=$this->preview($input);
        self::assertSame('preview_ready',$result->reason);
        self::assertTrue($result->isPreview());
        self::assertInstanceOf(SiteBriefDraft::class,$result->draft);
        self::assertSame('Apresentar nossos serviços com clareza para novos visitantes.',$result->draft->objective);
        self::assertSame(PortfolioProduct::Onepage,$result->draft->product);
        self::assertSame('DEMO-SITE-REQ-A1',$result->draft->reference);
        self::assertSame(0,$result->draft->revision);
        self::assertFalse($result->maySubmitRequest());
        self::assertFalse($result->mayWritePersonalData());
        self::assertFalse($result->mayPublishOrCharge());
        self::assertFalse($result->draft->canSubmitToPortal());
        self::assertFalse($result->draft->canPublishSite());
        self::assertFalse($result->draft->canChargeOrContract());
        self::assertSame(6,count($result->draft->illustration()));
        self::assertArrayNotHasKey('organization',$result->draft->illustration());
        self::assertTrue((new \ReflectionClass(SiteBriefDraft::class))->isReadOnly());
        self::assertTrue((new \ReflectionClass(SiteBriefPreviewResult::class))->isReadOnly());
    }

    public function test_landing_page_is_a_supported_draft_in_a_separate_demo_company(): void
    {
        $input=$this->input();
        $input['organization']='org-b';
        $input['reference']='DEMO-SITE-REQ-B22';
        $input['product']=PortfolioProduct::LandingPage->value;
        $input['revision']=4;
        $result=$this->preview($input,'org-b');
        self::assertTrue($result->isPreview());
        self::assertSame(PortfolioProduct::LandingPage,$result->draft->product);
        self::assertSame(4,$result->draft->revision);
        self::assertSame('org-b',$result->draft->organizationId->value);
        self::assertFalse($result->draft->canPublishSite());
    }

    public function test_mixed_tenant_is_rejected_before_inspecting_other_form_fields(): void
    {
        $input=$this->input();
        $input['product']='not-a-product';
        $input['objective']=['attacker'];
        $denied=$this->preview($input,'org-b');
        self::assertSame('mixed_organization',$denied->reason);
        self::assertFalse($denied->isPreview());
        self::assertNull($denied->draft);
        self::assertFalse($denied->mayWritePersonalData());
        $input['organization']='org-c';
        self::assertSame('mixed_organization',$this->preview($input,'org-c')->reason);
    }

    public function test_unexpected_fields_block_contact_information_documents_and_pricing(): void
    {
        foreach ([
            ['email'=>'a@company.example'],
            ['phone'=>'+55 11999999999'],
            ['cnpj'=>'11111111000111'],
            ['password'=>'test-secret'],
            ['payment_token'=>'DEMO'],
            ['attachment'=>['name'=>'test.pdf']],
            ['plan_price'=>69],
            ['consent'=>true],
            ['user_id'=>'DEMO-USER-A'],
        ] as $extra) {
            self::assertSame(
                'unexpected_fields',
                $this->preview(array_merge($this->input(),$extra))->reason,
            );
        }
    }

    public function test_missing_wrongly_named_or_numeric_keys_are_rejected_without_data(): void
    {
        $input=$this->input();
        unset($input['audience']);
        self::assertSame('unexpected_fields',$this->preview($input)->reason);
        $input=$this->input();
        $input['callToAction']=$input['call_to_action'];
        unset($input['call_to_action']);
        self::assertSame('unexpected_fields',$this->preview($input)->reason);
        self::assertSame('unexpected_fields',$this->preview(array_values($this->input()))->reason);
    }

    public function test_university_benefit_crm_and_performance_cannot_be_requested_as_a_site_brief(): void
    {
        foreach ([
            PortfolioProduct::ImagineCRM->value,
            PortfolioProduct::UniversidadeImagine->value,
            PortfolioProduct::PerformanceAI->value,
            'real_customer_account','sites_full_catalog',
        ] as $product) {
            $input=$this->input();
            $input['product']=$product;
            self::assertSame('unsupported_product',$this->preview($input)->reason);
        }
        $input=$this->input();
        $input['product']=1;
        self::assertSame('unsupported_product',$this->preview($input)->reason);
    }

    public function test_reference_and_revision_reject_real_or_unbounded_values(): void
    {
        foreach (['DEMO-REQ-1','../escape','customer-123',
            'DEMO-SITE-REQ-',str_repeat('D',60),'DEMO-SITE-REQ-lowercase'] as $ref) {
            $input=$this->input();
            $input['reference']=$ref;
            self::assertSame('invalid_reference',$this->preview($input)->reason);
        }
        foreach ([-1,100001,'0',0.0,null] as $rev) {
            $input=$this->input();
            $input['revision']=$rev;
            self::assertSame('invalid_revision',$this->preview($input)->reason);
        }
    }

    public function test_invalid_unicode_control_character_and_html_markup_are_rejected(): void
    {
        $unsafe=[
            'Objetivo <script>alert(1)</script> de nossa página de negócios',
            "Objetivo com\nquebra de linha não aprovada",
            "Objetivo \u{202E} com direção de texto forjada",
            "Objetivo \u{200B} com caractere invisível",
            "Objetivo \u{0000} com caractere binário",
        ];
        foreach ($unsafe as $goal) {
            $input=$this->input();
            $input['objective']=$goal;
            self::assertSame('unsafe_content',$this->preview($input)->reason);
        }
        $input=$this->input();
        $input['objective']="Objetivo de texto UTF-8 inválido \xC3\x28";
        self::assertSame('invalid_content',$this->preview($input)->reason);
    }

    public function test_empty_whitespace_oversized_or_wrong_typed_content_is_denied(): void
    {
        $values=[
            ''=>'content_out_of_bounds',
            ' '=>'content_out_of_bounds',
            'curto'=>'content_out_of_bounds',
            str_repeat('a',501)=>'content_out_of_bounds',
            str_repeat('ê',2100)=>'invalid_content',
        ];
        foreach ($values as $value=>$reason) {
            $input=$this->input();
            $input['objective']=$value;
            self::assertSame($reason,$this->preview($input)->reason);
        }
        $input=$this->input();
        $input['audience']=['array'];
        self::assertSame('invalid_content',$this->preview($input)->reason);
        $input=$this->input();
        $input['call_to_action']='sim';
        self::assertSame('content_out_of_bounds',$this->preview($input)->reason);
    }

    public function test_deterministic_utf8_normalization_does_not_create_any_side_effect(): void
    {
        $input=$this->input();
        $input['objective']='Página    profissional para  lojas locais com descrição objetiva';
        $first=$this->preview($input);
        $second=$this->preview($input);
        self::assertSame($first->reason,$second->reason);
        self::assertSame($first->draft->illustration(),$second->draft->illustration());
        self::assertSame($first->draft->organizationId->value,$second->draft->organizationId->value);
        self::assertFalse($first->maySubmitRequest());
        self::assertFalse($first->mayPublishOrCharge());
    }

    public function test_result_cannot_be_forged_as_operational_status(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SiteBriefPreviewResult('customer_authorized',null);
    }

    public function test_draft_constructor_does_not_accept_real_reference_or_unsupported_product(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SiteBriefDraft('REAL-REQUEST-123',new OrganizationId('org-a'),
            PortfolioProduct::UniversidadeImagine,'Objetivo de negócio',
            'Empresas locais','Solicitar contato',0);
    }
}
