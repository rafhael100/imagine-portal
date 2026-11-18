<?php
declare(strict_types=1);
// Standalone pure service composition. Existing App source root must be explicitly supplied.
$root=$argv[1]??'';if(!is_dir($root))throw new RuntimeException('EPOCH_SOURCE_REQUIRED');
spl_autoload_register(static function(string $c)use($root){if(str_starts_with($c,'App\\')){ $p=$root.'/'.str_replace('\\','/',substr($c,4)).'.php';if(is_file($p))require $p; }});
use App\Domain\Commerce\D0\{EntryStore,FirstBriefingRelease};
use App\Domain\SitesIntegration\{AsaasOfficialPaymentReader,PortalSettlementEvidence};
final class EpochMemory implements EntryStore {
 public int $sequence=1;public int $epoch=1;public string $config='cfg_original_001';public bool $active=true;public bool $exists=true;public int $writes=0;public bool $durable=false;public ?Closure $late=null;
 public function operator(string $operation):void{
  // Test-only operator, not browser/reader input. Sequence is independent of account lifetime.
  if($operation==='delete'){$this->exists=false;return;}
  $this->epoch=++$this->sequence;
  if($operation==='disable')$this->active=false;
  if($operation==='enable')$this->active=true;
  if($operation==='recreate'){$this->exists=true;$this->active=true;}
  if($operation==='config-b')$this->config='cfg_alternate_01';
  if($operation==='config-a')$this->config='cfg_original_001';
 }
 public function inspect(string $session,string $order):?array{
  if($session!=='native-fixture-session'||$order!=='native-fixture-order'||!$this->exists||!$this->active)return null;
  $s=['state'=>$this->durable?'confirmed':'ready','account'=>'synthetic-account','environment'=>'sandbox',
  'context'=>['tenant_ref'=>'fixture-tenant','contract_ref'=>'fixture-contract','contract_revision'=>1],
  'binding'=>['payment_id'=>'pay_EpochFixture1','customer_id'=>'cus_EpochFixture1','external_reference'=>'synthetic-reference','amount_cents'=>33000,'billing_type'=>'PIX']];
  if(!$this->durable)$s['account_authorization']=['generation'=>(string)$this->epoch,'configuration'=>$this->config];return $s;
 }
 public function admit(string $session,string $order,array $expected,PortalSettlementEvidence $p,string $at):string{
  if($this->late)($this->late)();$now=$this->inspect($session,$order);
  if($now===null)return 'denied';
  if(!$this->durable&&$now!==$expected)return 'denied';
  if($this->durable)return 'replay';$this->writes++;$this->durable=true;return 'recorded';
 }
}
final class EpochFake implements AsaasOfficialPaymentReader {
 public int $calls=0;public ?Closure $during=null;public bool $completed=false;public bool $fail=false;
 public function fetchPayment(string $id):?array{
  $this->calls++;$this->completed=false;if($this->during)($this->during)();$this->completed=true;if($this->fail)return null;
  return ['id'=>$id,'customer'=>'cus_EpochFixture1','externalReference'=>'synthetic-reference','value'=>'330.00','billingType'=>'PIX','status'=>'RECEIVED'];
 }
}
$n=0;$ok=static function(bool $v)use(&$n){if(!$v)throw new RuntimeException('EPOCH_OFFLINE_REFUSED');$n++;};
foreach(['disable','disable-enable','config-aba','delete-recreate','late-append'] as $kind){
 $s=new EpochMemory;$p=new EpochFake;$x=new FirstBriefingRelease($s,$p);
 $mutation=static function()use($s,$kind){
  if(in_array($kind,['disable','disable-enable','late-append'],true)){$s->operator('disable');if($kind!=='disable')$s->operator('enable');}
  elseif($kind==='config-aba'){$s->operator('config-b');$s->operator('config-a');}
  else{$s->operator('delete');$s->operator('recreate');}
 };
 if($kind==='late-append')$s->late=$mutation;else $p->during=$mutation;
 $ok($x->attempt('native-fixture-session','native-fixture-order')==='unavailable');$ok($p->completed&&$p->calls===1&&$s->writes===0);
 $p->during=null;$s->late=null;$s->operator('enable');
 $ok($x->attempt('native-fixture-session','native-fixture-order')==='briefing_available'&&$s->writes===1&&$p->calls===2);
 $s->operator('config-b');$s->operator('disable');$s->operator('enable');
 $ok($x->attempt('native-fixture-session','native-fixture-order')==='briefing_available'&&$s->writes===1&&$p->calls===2);
}
$s=new EpochMemory;$p=new EpochFake;$x=new FirstBriefingRelease($s,$p);
$ok($x->state('native-fixture-session','native-fixture-order')==='unavailable'&&$p->calls===0);
$ok($x->attempt('wrong','native-fixture-order')==='unavailable'&&$p->calls===0);
$ok($x->attempt('native-fixture-session','neighbor')==='unavailable'&&$p->calls===0);
$p->fail=true;$ok($x->attempt('native-fixture-session','native-fixture-order')==='unavailable'&&$s->writes===0);
$p->fail=false;$ok($x->attempt('native-fixture-session','native-fixture-order')==='briefing_available'&&$s->writes===1);
echo 'PASS EPOCH_SERVICE_OFFLINE '.$n." FAKE_PSP_MODEL_STORE_NO_SQL\n";
