<?php
declare(strict_types=1);
// Requires a validated vendor autoload supplied by the offline launcher; never boots the app.
require_once __DIR__.'/../briefing-session-hook/ObservedIds.php';require_once __DIR__.'/../briefing-session-hook/Channel.php';
require_once __DIR__.'/Client.php';require_once __DIR__.'/Pipe.php';
use NativeHttpBriefing\{Client,Pipe};
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Cookie;
$n=0;$ok=static function(bool $v)use(&$n):void{if(!$v)throw new RuntimeException('OFFLINE_REFUSED');$n++;};
$app=new class implements ArrayAccess {
 public int $reset=0;private object $sessions;
 public function __construct(){$this->sessions=new class{public function forgetDrivers(){}};}
 public function offsetExists(mixed $o):bool{return $o==='session';}
 public function offsetGet(mixed $o):mixed{return $this->sessions;}
 public function offsetSet(mixed $o,mixed $v):void{}
 public function offsetUnset(mixed $o):void{}
 public function forgetInstance($name){$this->reset++;}
};
$kernel=new class($ok) implements Illuminate\Contracts\Http\Kernel {
 public int $handled=0;public int $terminated=0;public bool $badCookie=false;public bool $badBody=false;public bool $empty204=false;public bool $failTerminate=false;
 public function __construct(private Closure $ok){}
 public function bootstrap(){}public function getApplication(){return null;}
 public function handle($r){
  $this->handled++;($this->ok)(!$r->hasSession());($this->ok)($r->isSecure());
  if($this->handled===1)($this->ok)($r->cookies->count()===0);
  if($r->method()==='POST'){($this->ok)($r->cookies->get('__Host-imagine-portal-session')==='issued-cookie');($this->ok)($r->header('X-CSRF-TOKEN')==='issued-csrf');}
  $response=new JsonResponse(['csrf_token'=>'issued-csrf']);
  $response->headers->setCookie(new Cookie('__Host-imagine-portal-session','issued-cookie',0,'/',null,!$this->badCookie,true));
  if($this->badBody)$response->setContent('invalid-json');
  if($this->empty204){$response->setStatusCode(204);$response->setContent('');}return $response;
 }
 public function terminate($r,$response){$this->terminated++;if($this->failTerminate)throw new RuntimeException('TERMINATE_EXPECTED_REFUSAL');}
};
$c=new Client($app,$kernel);$c->post('/_development/auth/password',['email'=>'synthetic@example.invalid']);$ok($kernel->terminated===2);
$kernel->badCookie=true;try{$c->call('GET','/_development/auth/csrf');$ok(false);}catch(RuntimeException){$ok($kernel->terminated===3);}$kernel->badCookie=false;
$kernel->badBody=true;try{$c->call('GET','/_development/auth/csrf');$ok(false);}catch(JsonException){$ok($kernel->terminated===4);}
$ok($app->reset===4);
$kernel->badBody=false;$kernel->empty204=true;
[$status,$body]=$c->call('POST','/_development/auth/logout',[],'issued-csrf');$ok($status===204&&$body===[]&&$kernel->terminated===5);
$kernel->failTerminate=true;
try{$c->call('POST','/_development/auth/logout',[],'issued-csrf');$ok(false);}catch(RuntimeException $e){$ok($e->getMessage()==='TERMINATE_EXPECTED_REFUSAL'&&$kernel->terminated===6);}
$q='00000000-0000-4000-8000-000000000001';$o='00000000-0000-4000-8000-000000000002';
$d=['status'=>'draft','id'=>$q,'order_created'=>false,'payment_requested'=>false,'site_provisioned'=>false];
$a=['status'=>'pending_payment','id'=>$o,'quote_id'=>$q,'payment_requested'=>false,'site_provisioned'=>false];
$value=Pipe::frame($d,$a,static function($channel)use($q,$o,$ok){$ids=$channel->consume();$ok($ids->quoteId===$q&&$ids->orderId===$o);return true;});$ok($value);
try{Pipe::frame($d,$a,static function($channel){$channel->consume();throw new DomainException('EXPECTED_CALLBACK_REFUSAL');});$ok(false);}catch(DomainException $e){$ok($e->getMessage()==='EXPECTED_CALLBACK_REFUSAL');}
echo 'PASS HTTP_CLIENT_PIPE_OFFLINE '.$n."; MODEL_KERNEL_REAL_PIPE_NO_DB\n";
