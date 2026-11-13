<?php

declare(strict_types=1);
namespace Tests\Feature;

use App\Homologation\RoleBoundTestPurchase;
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
        $this->app->instance(RoleBoundTestPurchase::class,new RoleBoundTestPurchase($db));
    }

    public function test_three_offers_on_same_page_have_independent_order_keys(): void
    {
        $this->scope->tenant='test-org-a';
        $this->freshStore();
        $base='/_rehearsal/purchase';
        $html=$this->get($base)->assertOk()->getContent();
        self::assertIsString($html);
        self::assertSame(3, preg_match_all(
            '/name="request_key" value="(TEST-[a-f0-9]{32})"/',
            $html, $matches
        ));
        self::assertCount(3,array_unique($matches[1]));
        $orderUrls=[];
        foreach (['onepage','landing-pages','performance'] as $index=>$product) {
            $data=['product'=>$product,'request_key'=>$matches[1][$index]];
            $first=$this->post($base.'/orders',$data)->assertRedirect();
            $url=$first->headers->get('Location');
            self::assertIsString($url);
            self::assertStringContainsString('/orders/TEST-', $url);
            $this->post($base.'/orders',$data)->assertRedirect($url);
            $orderUrls[]=$url;
        }
        self::assertCount(3,array_unique($orderUrls));
        // A replay under the same key with a different product MUST fail.
        $this->post($base.'/orders',[
            'product'=>'performance','request_key'=>$matches[1][0]
        ])->assertStatus(409);
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
            // A forged server-side organization fixture cannot override the
            // still-authenticated PostgreSQL role A. Fail closed BEFORE read.
            $this->scope->tenant='test-org-b';
            $this->get($url)->assertForbidden();
            $this->post($url.'/payment',[
                'revision'=>0,'outcome'=>'approved','sample_ack'=>1
            ])->assertForbidden();
            // Only a fresh authenticated database role B is accepted, and
            // its FORCE RLS policy makes organization A's order invisible.
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

    public function test_refuses_scope_database_mismatch_and_revoked_scope(): void
    {
        $base='/_rehearsal/purchase';
        $this->scope->tenant='test-org-a';
        $created=$this->post($base.'/orders',[
            'product'=>'onepage','request_key'=>'TEST-scope-drift-'.bin2hex(random_bytes(12)),
        ])->assertRedirect();
        $url=$created->headers->get('Location');
        // Keep the actual SQL login A while the principal fixture claims B.
        // This must fail before invoking the store, not silently write for A.
        $this->scope->tenant='test-org-b';
        $this->get($url)->assertForbidden();
        $this->post($base.'/orders',['product'=>'onepage','request_key'=>'TEST-scope-forged-1234'])->assertForbidden();
        $this->post($url.'/payment',['revision'=>0,'outcome'=>'approved','sample_ack'=>1])->assertForbidden();
        $this->post($url.'/briefing',['revision'=>0])->assertForbidden();
        $this->scope->tenant=null;
        $this->get($url)->assertForbidden();
        $this->post($url.'/payment',['revision'=>0,'outcome'=>'approved','sample_ack'=>1])->assertForbidden();
        $this->scope->tenant='test-org-a';
        $this->get($url)->assertOk()->assertDontSee('Salvar briefing de teste');
    }

    public function test_commercial_offer_hint_uses_server_allowlist_and_does_not_choose_identity(): void
    {
        $base='/_rehearsal/purchase';
        $this->scope->tenant='test-org-a';
        foreach (['onepage','landing-pages','performance'] as $offer) {
            $this->get($base.'?offer='.$offer)
                ->assertOk()->assertSee('data-selected-offer="'.$offer.'"',false);
        }
        $this->get($base.'?offer=other')->assertNotFound();
        $this->get($base.'?offer%5B%5D=onepage')->assertNotFound();
        $this->get($base.'?offer=onepage&organization=test-org-b')->assertUnprocessable();
        $this->scope->tenant=null;
        $this->get($base.'?offer=onepage')->assertForbidden();
    }

    public function test_runtime_failure_is_redacted_before_http_response(): void
    {
        $this->scope->tenant='test-org-a';
        $this->app->forgetInstance(RoleBoundTestPurchase::class);
        $this->app->bind(RoleBoundTestPurchase::class,static function() {
            throw new \RuntimeException('SQL_DIAGNOSTIC_MUST_NOT_LEAK');
        });
        $this->get('/_rehearsal/purchase')->assertStatus(503)
            ->assertDontSee('SQL_DIAGNOSTIC_MUST_NOT_LEAK',false);
    }

}
