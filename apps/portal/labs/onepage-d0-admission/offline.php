<?php
declare(strict_types=1);
use App\Domain\Commerce\D0\{EntryStore,FirstBriefingRelease};
use App\Domain\SitesIntegration\{AsaasOfficialPaymentReader,PortalSettlementEvidence};
use App\Http\Controllers\OnepageFirstBriefingController;
use App\Http\Middleware\PortalBrowserAuthGuard;
use Illuminate\Http\Request;
use Illuminate\Session\{Store,ArraySessionHandler};
$loader=require $argv[1]; $loader->setPsr4('App\\',dirname(__DIR__,2).'/app'); // Explicit validated local vendor passed by offline launcher, no fallback.
spl_autoload_register(static function($c){if(str_starts_with($c,'App\\')){ $f=dirname(__DIR__,2).'/app/'.str_replace('\\','/',substr($c,4)).'.php';if(is_file($f))require $f; }});
$n=0;$ok=static function(bool $v)use(&$n){if(!$v)throw new RuntimeException('D0_OFFLINE_REFUSED');$n++;};
final class MemoryD0 implements EntryStore {
 public bool $active=true;public int $writes=0;public $beforeAppend=null;
 public array $s=['state'=>'ready','account'=>'synthetic','environment'=>'sandbox','context'=>['tenant_ref'=>'synthetic-company','contract_ref'=>'synthetic-contract','contract_revision'=>1],
 'binding'=>['payment_id'=>'pay_Synthetic001','customer_id'=>'cus_Synthetic001','external_reference'=>'synthetic-entry-reference','amount_cents'=>33000,'billing_type'=>'PIX']];
 public function inspect(string $session,string $order):?array{return $this->active&&$session===str_repeat('a',64)&&$order==='00000000-0000-4000-8000-000000000001'?$this->s:null;}
 public function admit(string $session,string $order,array $expected,PortalSettlementEvidence $p,string $at):string{
  if($this->beforeAppend)($this->beforeAppend)($this);
  if($this->inspect($session,$order)!==$expected)return 'denied';
  $this->writes++;$this->s['state']='confirmed';return 'recorded';
 }
}
final class FakeD0Payment implements AsaasOfficialPaymentReader {
 public int $calls=0;public $during=null;public bool $fail=false;public string $status='RECEIVED';
 public function fetchPayment(string $id):?array{$this->calls++;if($this->fail)throw new RuntimeException('SYNTHETIC');if($this->during)($this->during)();return ['id'=>$id,'customer'=>'cus_Synthetic001','externalReference'=>'synthetic-entry-reference','billingType'=>'PIX','status'=>$this->status,'value'=>'330.00'];}
}
$sess=str_repeat('a',64);$order='00000000-0000-4000-8000-000000000001';
$m=new MemoryD0;$p=new FakeD0Payment;$service=new FirstBriefingRelease($m,$p);
$ok($service->state($sess,$order)==='unavailable'&&$p->calls===0);
foreach([['bad',$order],[$sess,'00000000-0000-4000-8000-000000000002']] as [$s,$o])$ok($service->attempt($s,$o)==='unavailable'&&$p->calls===0);
$ok($service->attempt($sess,$order)==='briefing_available'&&$m->writes===1&&$p->calls===1);
$ok($service->attempt($sess,$order)==='briefing_available'&&$m->writes===1&&$p->calls===1);
$p->fail=true;$ok($service->state($sess,$order)==='briefing_available'&&$p->calls===1);$ok($service->attempt($sess,$order)==='briefing_available'&&$p->calls===1);
$m->active=false;$ok($service->state($sess,$order)==='unavailable'&&$m->writes===1);
foreach(['PENDING','CONFIRMED'] as $status){$m=new MemoryD0;$p=new FakeD0Payment;$p->status=$status;$x=new FirstBriefingRelease($m,$p);$ok($x->attempt($sess,$order)==='unavailable'&&$m->writes===0);}
$m=new MemoryD0;$p=new FakeD0Payment;$p->fail=true;$x=new FirstBriefingRelease($m,$p);$ok($x->attempt($sess,$order)==='unavailable'&&$m->writes===0);$p->fail=false;$ok($x->attempt($sess,$order)==='briefing_available'&&$p->calls===2);
foreach(['active','revision','account'] as $kind){$m=new MemoryD0;$p=new FakeD0Payment;$p->during=static function()use($m,$kind){if($kind==='active')$m->active=false;elseif($kind==='revision')$m->s['context']['contract_revision']=2;else $m->s['account']='neighbor';};$x=new FirstBriefingRelease($m,$p);$ok($x->attempt($sess,$order)==='unavailable'&&$m->writes===0);}
$m=new MemoryD0;$p=new FakeD0Payment;$m->beforeAppend=static function($m){$m->active=false;};$x=new FirstBriefingRelease($m,$p);$ok($x->attempt($sess,$order)==='unavailable'&&$m->writes===0); // model only: SQL must prove locking separately
$ok((new FirstBriefingRelease())->attempt($sess,$order)==='unavailable');
// Real HTTP controller and native guard, synthetic session transport only: not native login/MFA proof.
$app=new Illuminate\Foundation\Application(dirname(__DIR__,2));Illuminate\Container\Container::setInstance($app);
$app->instance('config',new Illuminate\Config\Repository(['app'=>['url'=>'https://portal-dev.imaginecomunica.com.br'],'portal_onepage_d0'=>['enabled'=>true]]));
$app->instance(Illuminate\Contracts\Routing\ResponseFactory::class,(new ReflectionClass(Illuminate\Routing\ResponseFactory::class))->newInstanceWithoutConstructor());
$m=new MemoryD0;$p=new FakeD0Payment;$ctrl=new OnepageFirstBriefingController(new FirstBriefingRelease($m,$p));$guard=new PortalBrowserAuthGuard;
$make=static function(string $method,string $body='{}',bool $csrf=true)use($sess):Request{
 $r=Request::create('https://portal-dev.imaginecomunica.com.br/_development/commerce/onepage/orders/test',$method,[],[],[],['CONTENT_TYPE'=>'application/json','HTTP_ORIGIN'=>'https://portal-dev.imaginecomunica.com.br'],$body);
 $st=new Store('synthetic',new ArraySessionHandler(120));$st->put('portal.browser_auth',['phase'=>'business','reference'=>$sess]);$st->put('_token','synthetic-csrf');$r->setLaravelSession($st);if($csrf)$r->headers->set('X-CSRF-TOKEN','synthetic-csrf');return $r;
};
$r=$make('POST','{}',false);$ok($guard->handle($r,fn($r)=>$ctrl->attempt($r,$order))->getStatusCode()===419&&$p->calls===0);
$r=$make('POST','{"paid":true}');$ok($guard->handle($r,fn($r)=>$ctrl->attempt($r,$order))->getStatusCode()===422&&$p->calls===0);
$r=$make('GET');$ok($guard->handle($r,fn($r)=>$ctrl->state($r,$order))->getStatusCode()===200&&$p->calls===0);
$r=$make('POST');$ok($guard->handle($r,fn($r)=>$ctrl->attempt($r,$order))->getStatusCode()===200&&$p->calls===1);
$r=$make('GET');$ok($guard->handle($r,fn($r)=>$ctrl->state($r,$order))->getStatusCode()===200&&$p->calls===1);
$app['config']->set('portal_onepage_d0.enabled',false);$ok($ctrl->attempt($make('POST'),$order)->getStatusCode()===404&&$p->calls===1);
echo 'PASS D0_OFFLINE '.$n."; FAKE_PSP_NO_NETWORK_NO_SQL\n";
