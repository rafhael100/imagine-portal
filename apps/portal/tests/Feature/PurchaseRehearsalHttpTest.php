<?php

declare(strict_types=1);
namespace Tests\Feature;

use App\Homologation\PersistedTestPurchase;
use App\Homologation\PurchaseRehearsalScope;
use App\Providers\PurchaseRehearsalServiceProvider;
use Illuminate\Http\Request;
use PDO;
use Tests\TestCase;

final class PurchaseRehearsalHttpTest extends TestCase
{
    private object $scope;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('PORTAL_PURCHASE_HTTP_CI')!=='1') $this->markTestSkipped('Requires dedicated disposable PostgreSQL purchase workflow.');
        $this->app->register(PurchaseRehearsalServiceProvider::class);
        $this->scope=new class implements PurchaseRehearsalScope {
            public ?string $tenant=null;
            public function organization(Request $request): ?string { return $this->tenant; }
        };
        $this->app->instance(PurchaseRehearsalScope::class,$this->scope);
        $this->freshStore();
    }

    private function freshStore(): void
    {
        $role=$this->scope->tenant==='test-org-b'?'purchase_rehearsal_b':'purchase_rehearsal_a';
        $password=$this->scope->tenant==='test-org-b'?'ci-synthetic-b-only':'ci-synthetic-a-only';
        $db=new PDO('pgsql:host=127.0.0.1;port=5432;dbname=portal_purchase_rehearsal',$role,$password);
        $this->app->instance(PersistedTestPurchase::class,new PersistedTestPurchase($db));
    }

    public function test_real_laravel_forms_persist_three_synthetic_purchase_flows(): void
    {
        $base='/_rehearsal/purchase';
        $this->get($base)->assertForbidden();
        $this->scope->tenant='test-org-a';
        $this->get($base)->assertOk()->assertSee('Criar pedido de teste');
        $this->post($base.'/orders',['product'=>'onepage','request_key'=>'TEST-forged-organization','organization'=>'test-org-b'])->assertUnprocessable();
        foreach (['onepage','landing-pages','performance'] as $product) {
            $data=['product'=>$product,'request_key'=>'TEST-http-'.bin2hex(random_bytes(12))];
            $created=$this->post($base.'/orders',$data)->assertRedirect();
            $url=$created->headers->get('Location');
            $this->post($base.'/orders',$data)->assertRedirect($url);
            $this->get($url)->assertOk()->assertSee('Seu pedido de teste')->assertDontSee('Salvar briefing de teste');
            $this->scope->tenant='test-org-b';
            $this->freshStore();
            $this->get($url)->assertNotFound();
            $this->scope->tenant='test-org-a';
            $this->freshStore();
            $brief=['revision'=>0,'business'=>'Empresa fictícia','goal'=>'<script>alert(1)</script>','audience'=>'Exemplo','materials'=>'Amostras'];
            $this->post($url.'/briefing',$brief)->assertStatus(409);
            $this->post($url.'/payment',['revision'=>0,'outcome'=>'approved'])->assertSessionHasErrors('sample_ack');
            $this->post($url.'/payment',['revision'=>0,'outcome'=>'declined','sample_ack'=>1])->assertRedirect($url);
            $this->post($url.'/payment',['revision'=>0,'outcome'=>'approved','sample_ack'=>1])->assertStatus(409);
            $this->get($url)->assertSee('Recusado')->assertDontSee('Salvar briefing de teste');
            $this->post($url.'/payment',['revision'=>1,'outcome'=>'approved','sample_ack'=>1])->assertRedirect($url);
            $this->get($url)->assertSee('Salvar briefing de teste');
            $brief['revision']=2;
            $this->post($url.'/briefing',$brief)->assertRedirect($url);
            $this->freshStore();
            $this->get($url)->assertOk()->assertSee('Briefing salvo')->assertSee('&lt;script&gt;',false)->assertDontSee('<script>alert(1)</script>',false);
            $this->scope->tenant=null;
            $this->get($url)->assertForbidden();
            $this->scope->tenant='test-org-a';
        }
    }
}
