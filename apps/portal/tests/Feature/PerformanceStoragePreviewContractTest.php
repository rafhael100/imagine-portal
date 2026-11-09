<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Presentation\Preview\PerformanceStoragePreview;
use Tests\TestCase;

final class PerformanceStoragePreviewContractTest extends TestCase
{
    /** @return array<string,mixed> */
    private function sample(): array
    {
        return json_decode(
            (string) file_get_contents(
                base_path('preview/fixtures/performance-storage-preview-v1.json')
            ),
            true, 32, JSON_THROW_ON_ERROR
        );
    }

    /** @param array<string,mixed> $payload
     *  @return array<string,mixed>|null */
    private function valid(array $payload): ?array
    {
        return PerformanceStoragePreview::parse(
            json_encode($payload,JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'org-demo-a','perf-demo-a'
        );
    }

    public function test_reads_exact_fictional_pg_contract_and_shows_read_only_preview(): void
    {
        $sample=$this->sample();
        $this->assertSame($sample,$this->valid($sample));
        $this->assertSame($sample,PerformanceStoragePreview::loadStatic());
        $this->assertNull(PerformanceStoragePreview::loadStatic('org-demo-b'));
        $this->assertNull(PerformanceStoragePreview::loadStatic('org-demo-a','perf-demo-b'));

        $this->assertFalse($sample['contracted']);
        $this->assertFalse($sample['dispatch_enabled']);
        $this->assertTrue($sample['read_only']);
        $this->assertNull($sample['metrics']['clicks']);
        $this->assertNull($sample['metrics']['media_spend']);
        $this->assertSame(2,$sample['metrics']['managed_campaigns']);
        $this->assertSame(1,$sample['metrics']['paused_campaigns']);
        $this->assertSame(1,$sample['metrics']['approvals_pending']);
        $this->assertSame(2,$sample['metrics']['approvals_approved_internal']);
        $this->assertSame(1,$sample['metrics']['approvals_rejected']);
        $this->assertCount(2,$sample['campaigns']);
        $this->assertCount(4,$sample['approvals']);

        $html=view('portal.demo.pview-navigation')->render();
        $this->assertStringContainsString(
            'data-pview-perf-storage="synthetic-pg-offline"',$html
        );
        $this->assertStringContainsString('Exemplo PostgreSQL',$html);
        $this->assertStringContainsString('A One (exemplo)',$html);
        $this->assertStringContainsString('F23 test draft (exemplo)',$html);
        $this->assertStringContainsString('Pausada (DEMO)',$html);
        $this->assertStringContainsString(
            'Aprovada internamente, sem execução',$html
        );
        $this->assertStringContainsString('Aguardando análise (DEMO)',$html);
        $this->assertStringContainsString('Substituir campanha',$html);
        $this->assertStringContainsString('Sem cliques ou investimento real',$html);
        $this->assertSame(8,preg_match_all('/data-screen="[a-z]+"/',$html));
        $this->assertSame(8,preg_match_all('/data-nav="[a-z]+"/',$html));
    }

    public function test_deny_untrusted_pg_contract_mutations_with_zero_partial_rendering(): void
    {
        $base=$this->sample();
        $mutations=[
            'schema_version' => static function(array &$x):void{$x['schema_version']='live';},
            'producer' => static function(array &$x):void{$x['producer']='unsafe';},
            'source' => static function(array &$x):void{$x['source']='real_sql';},
            'organization' => static function(array &$x):void{$x['portal_organization_ref']='org-demo-b';},
            'tenant' => static function(array &$x):void{$x['performance_tenant_ref']='perf-demo-b';},
            'service' => static function(array &$x):void{$x['portal_service_ref']='svc-demo-b';},
            'read_only' => static function(array &$x):void{$x['read_only']=false;},
            'contracted' => static function(array &$x):void{$x['contracted']=true;},
            'dispatch' => static function(array &$x):void{$x['dispatch_enabled']=true;},
            'capacity' => static function(array &$x):void{$x['commercial']['capacity_state']='unlimited';},
            'price' => static function(array &$x):void{$x['commercial']['price_state']='approved';},
            'real_clicks' => static function(array &$x):void{$x['metrics']['clicks']=100;},
            'real_media_spend' => static function(array &$x):void{$x['metrics']['media_spend']=15.50;},
            'wrong_managed' => static function(array &$x):void{$x['metrics']['managed_campaigns']=3;},
            'wrong_paused' => static function(array &$x):void{$x['metrics']['paused_campaigns']=0;},
            'wrong_pending' => static function(array &$x):void{$x['metrics']['approvals_pending']=0;},
            'wrong_approved' => static function(array &$x):void{$x['metrics']['approvals_approved_internal']=3;},
            'wrong_rejected' => static function(array &$x):void{$x['metrics']['approvals_rejected']=0;},
            'negative_count' => static function(array &$x):void{$x['metrics']['managed_campaigns']=-1;},
            'string_count' => static function(array &$x):void{$x['metrics']['managed_campaigns']='2';},
            'extra_field' => static function(array &$x):void{$x['access_token']='token';},
            'metric_pii' => static function(array &$x):void{$x['metrics']['customer_email']='test@example.test';},
            'commercial_extra' => static function(array &$x):void{$x['commercial']['billing_ref']='customer';},
            'campaign_id' => static function(array &$x):void{$x['campaigns'][0]['ref']='../../secret';},
            'campaign_dup' => static function(array &$x):void{$x['campaigns'][1]['ref']=$x['campaigns'][0]['ref'];},
            'campaign_live' => static function(array &$x):void{$x['campaigns'][0]['state']='active_real';},
            'campaign_unmanaged' => static function(array &$x):void{$x['campaigns'][0]['managed']=false;},
            'campaign_channel' => static function(array &$x):void{$x['campaigns'][0]['channel']='tiktok';},
            'campaign_html' => static function(array &$x):void{$x['campaigns'][0]['title']='<script>test</script> (exemplo)';},
            'campaign_title' => static function(array &$x):void{$x['campaigns'][0]['title']='Anuncio real';},
            'campaign_extra' => static function(array &$x):void{$x['campaigns'][0]['budget']=99;},
            'campaign_missing' => static function(array &$x):void{array_pop($x['campaigns']);},
            'campaign_not_list' => static function(array &$x):void{$x['campaigns']=['a'=>$x['campaigns'][0]];},
            'approval_unknown_ref' => static function(array &$x):void{$x['approvals'][0]['ref']='rogue';},
            'approval_dup' => static function(array &$x):void{$x['approvals'][1]['ref']=$x['approvals'][0]['ref'];},
            'approval_foreign_target' => static function(array &$x):void{$x['approvals'][0]['campaign_ref']='demo-cam-foreign';},
            'approval_real_execution' => static function(array &$x):void{$x['approvals'][0]['status']='published';},
            'approval_operation' => static function(array &$x):void{$x['approvals'][0]['operation']='billing.charge';},
            'approval_manifest' => static function(array &$x):void{$x['approvals'][0]['manifest_version']='prod-v1';},
            'approval_extra_pii' => static function(array &$x):void{$x['approvals'][0]['reviewer_email']='user@example.test';},
            'approval_not_list' => static function(array &$x):void{$x['approvals']=['key'=>$x['approvals'][0]];},
            'deleted_key' => static function(array &$x):void{unset($x['metrics']['clicks']);},
        ];
        foreach($mutations as $name=>$change){
            $copy=$base;
            $change($copy);
            $this->assertNull($this->valid($copy),'Mutation accepted: '.$name);
        }
        $this->assertNull(
            PerformanceStoragePreview::parse('invalid','org-demo-a','perf-demo-a')
        );
        $this->assertNull(
            PerformanceStoragePreview::parse('{}','org-demo-a','perf-demo-a')
        );
        $this->assertNull(
            PerformanceStoragePreview::parse('','org-demo-a','perf-demo-a')
        );
        $this->assertNull(
            PerformanceStoragePreview::parse(
                str_repeat('x',32769),'org-demo-a','perf-demo-a'
            )
        );
        $this->assertNull(
            PerformanceStoragePreview::parse(
                json_encode($base,JSON_THROW_ON_ERROR),'org-demo-b','perf-demo-a'
            )
        );
    }

    public function test_no_public_performance_route_or_action_exposed_by_demo(): void
    {
        $this->get('/performance')->assertNotFound();
        $this->get('/portal/performance')->assertNotFound();
        $this->get('/portal/performance/approve')->assertNotFound();
        $this->post('/portal/performance/approve')->assertNotFound();
        $html=view('portal.demo.pview-navigation')->render();
        foreach(['<form','<input','<button','fetch(',
            'XMLHttpRequest','navigator.sendBeacon','<iframe'] as $unsafe) {
            $this->assertStringNotContainsString($unsafe,$html);
        }
        $this->assertSame(
            'Meta (Instagram e/ou Facebook)',
            PerformanceStoragePreview::channelLabel('meta')
        );
        $this->assertSame(
            'Canal indisponível',
            PerformanceStoragePreview::channelLabel('other')
        );
    }
}
