<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Homologation\CustomerPreviewStore;
use App\Homologation\PreviewProfiles;
use App\Http\Controllers\CustomerPreviewController;
use App\Http\Controllers\FullPortalPreviewController;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Request;
use Illuminate\Session\Store;
use Illuminate\Support\ViewErrorBag;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

/**
 * Context-only regression: actual controllers, Request/session and Blade;
 * in-memory registration/login fixtures deliberately make no auth/PG claim.
 * The baseline methods need no new helper and can run against parent 26075ce0.
 */
final class CustomerPreviewV5HandoffTest extends TestCase
{
    private Store $session;
    private object $profiles;

    protected function setUp(): void
    {
        parent::setUp();
        $db=$this->createMock(DatabaseManager::class);
        $db->expects($this->never())->method('connection');
        $this->app->instance('db',$db);
        $this->session=$this->app['session']->driver();
        $this->session->start();
        view()->share('errors',new ViewErrorBag());
        $this->profiles=new class {
            public ?array $profile=null;
            public array $next=['role'=>'client','customer_id'=>'synthetic-customer'];
            public function ready(): bool {return true;}
            public function current(Request $request): ?array {return $this->profile;}
            public function login(Request $request,string $id,string $password): bool
            {
                // Exercise the context-erasing session rotation which the
                // real PreviewProfiles login performs, without authenticating.
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                $this->profile=$this->next;
                return true;
            }
        };
        $this->app->instance(PreviewProfiles::class,$this->profiles);
        $this->app->instance(CustomerPreviewStore::class,new class {
            public function register(string $id,string $password,string $name,string $company): void {}
        });
    }

    private function request(string $path,string $method='GET',array $input=[]): Request
    {
        $request=Request::create('https://portal.example.test'.$path,$method,$input);
        $request->setLaravelSession($this->session);
        $this->app->instance('request',$request);
        $this->app['url']->setRequest($request);
        return $request;
    }

    public static function ctas(): array
    {
        return [
            'onepage'=>['product=onepage'],
            'landing'=>['product=landing-pages'],
            'google'=>['product=performance&channel=google_ads'],
            'meta'=>['product=performance&channel=meta_ads'],
        ];
    }

    #[DataProvider('ctas')]
    public function test_registration_preserves_context_across_session_rotation(string $query): void
    {
        $controller=new CustomerPreviewController();
        $controller->shop($this->request('/_preview/portal/shop?'.$query));
        $oldId=$this->session->getId();
        $response=$controller->register($this->request('/_preview/portal/register','POST',[
            'username'=>'demo-context-user','password'=>'synthetic-context-password',
            'password_confirmation'=>'synthetic-context-password',
            'name'=>'Synthetic person','company'=>'Synthetic company',
        ]));
        self::assertNotSame($oldId,$this->session->getId());
        self::assertSame('https://portal.example.test/_preview/portal/shop?'.$query,
            $response->getTargetUrl(),'ASSERT_V5_CHANNEL_LOST: registration dropped the exact CTA context');
    }

    #[DataProvider('ctas')]
    public function test_login_preserves_context_across_session_rotation(string $query): void
    {
        (new CustomerPreviewController())->shop($this->request('/_preview/portal/shop?'.$query));
        $oldId=$this->session->getId();
        $response=(new FullPortalPreviewController())->authenticate(
            $this->request('/_preview/portal/login','POST',[
                'username'=>'demo-context-user','password'=>'synthetic-context-password',
            ]));
        self::assertNotSame($oldId,$this->session->getId());
        self::assertSame('https://portal.example.test/_preview/portal/shop?'.$query,
            $response->getTargetUrl(),'ASSERT_V5_CHANNEL_LOST: login dropped the exact CTA context');
    }

    public function test_new_selection_clears_an_old_channel_and_invalid_pairs_fail_closed(): void
    {
        $controller=new CustomerPreviewController();
        foreach(['product=onepage','product=landing-pages','product=performance',''] as $replacement) {
            $controller->shop($this->request('/_preview/portal/shop?product=performance&channel=google_ads'));
            $controller->shop($this->request('/_preview/portal/shop?'.$replacement));
            self::assertNull($this->session->get('preview.intent_channel'));
            if ($replacement==='')self::assertNull($this->session->get('preview.intent'));
        }
        foreach(['product=onepage&channel=google_ads','product=performance&channel=chatgpt_ads',
            'product=performance&channel[]=google_ads','channel=meta_ads',
            'product=performance&channel=%3Cscript%3E'] as $query) {
            $controller->shop($this->request('/_preview/portal/shop?product=performance&channel=google_ads'));
            try {
                $controller->shop($this->request('/_preview/portal/shop?'.$query));
                self::fail('Invalid marketing context was accepted');
            } catch(HttpExceptionInterface $error) {
                self::assertSame(422,$error->getStatusCode());
            }
            self::assertNull($this->session->get('preview.intent_channel'));
            self::assertNull($this->session->get('preview.intent'));
        }
    }

    public function test_channel_is_only_an_allowlisted_notice_and_never_a_budget_or_order_field(): void
    {
        $this->profiles->profile=$this->profiles->next;
        foreach(['google_ads','meta_ads'] as $channel) {
            $response=(new CustomerPreviewController())->shop(
                $this->request('/_preview/portal/shop?product=performance&channel='.$channel));
            $html=$response->getContent();
            self::assertStringContainsString('data-channel-origin="'.$channel.'"',$html);
            self::assertSame(1,substr_count($html,'data-channel-origin='));
            self::assertStringContainsString('nenhum investimento foi preenchido automaticamente',$html);
            // Keep the current V5 presentation rather than importing the old
            // controller's R$700/prepaid terms with its contextual helper.
            self::assertStringContainsString('Ativação única de R$ 490',$html);
            self::assertStringContainsString('mínimo mensal de R$ 170',$html);
            self::assertStringContainsString('R$ 1.000 no Google Ads',$html);
            self::assertStringNotContainsString('Honorários antecipados',$html);
            $document=new \DOMDocument();
            @$document->loadHTML($html);
            $xpath=new \DOMXPath($document);
            foreach(['google_media','meta_media'] as $name) {
                $nodes=$xpath->query('//input[@name="'.$name.'"]');
                self::assertSame(1,$nodes->length);
                self::assertSame('',$nodes->item(0)->getAttribute('value'));
            }
            self::assertSame(0,$xpath->query('//input[@name="channel" or @name="amount_cents"]')->length);
        }
    }

    public function test_tampered_session_context_cannot_become_a_redirect_or_bypass_customer_scope(): void
    {
        $this->session->put('preview.intent','performance');
        $this->session->put('preview.intent_channel','https://outside.example.invalid');
        $controller=new FullPortalPreviewController();
        $response=$controller->authenticate($this->request('/_preview/portal/login','POST',[
            'username'=>'demo-context-user','password'=>'synthetic-context-password',
        ]));
        self::assertSame('https://portal.example.test/_preview/portal/client/overview',$response->getTargetUrl());
        $this->session->put('preview.intent','performance');
        $this->session->put('preview.intent_channel','google_ads');
        $this->profiles->next=['role'=>'admin'];
        $response=$controller->authenticate($this->request('/_preview/portal/login','POST',[
            'username'=>'demo-context-user','password'=>'synthetic-context-password',
        ]));
        self::assertSame('https://portal.example.test/_preview/portal/admin/overview',$response->getTargetUrl());
    }
}
