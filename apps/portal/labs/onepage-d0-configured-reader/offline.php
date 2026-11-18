<?php
declare(strict_types=1);
$root=$argv[1]??'';$client=$argv[2]??'';if(!is_dir($root)||!is_file($client))throw new RuntimeException('SOURCE_REQUIRED');
$local=dirname(__DIR__,2).'/app';
spl_autoload_register(static function(string $c)use($root,$local){if(str_starts_with($c,'App\\'))foreach([$local,$root] as $r){$p=$r.'/'.str_replace('\\','/',substr($c,4)).'.php';if(is_file($p)){require $p;return;}}});
require __DIR__.'/CurlFixture.php';require $client;
use App\Domain\Commerce\D0\{EntryStore,FirstBriefingRelease};
use App\Domain\SitesIntegration\{PortalSettlementEvidence,D0CurlFixture as Wire};
use App\Infrastructure\Commerce\ConfiguredSandboxD0Reader as Reader;
final class StoreFixture implements EntryStore {
 public int $writes=0;public ?Closure $late=null;
 public array $s=['state'=>'ready','account'=>'fixture-account','environment'=>'sandbox','account_authorization'=>['generation'=>'1','configuration'=>'cfg_original_001'],
 'context'=>['tenant_ref'=>'fixture-tenant','contract_ref'=>'fixture-contract','contract_revision'=>1],
 'binding'=>['payment_id'=>'pay_ConfigFixture1','customer_id'=>'cus_ConfigFixture1','external_reference'=>'fixture-reference','amount_cents'=>33000,'billing_type'=>'PIX']];
 public function inspect(string $session,string $order):?array{return $session==='fixture-session'&&$order==='fixture-order'?$this->s:null;}
 public function admit(string $session,string $order,array $expected,PortalSettlementEvidence $proof,string $at):string{
  if($this->late)($this->late)();if($this->inspect($session,$order)!==$expected)return 'denied';$this->writes++;$this->s['state']='confirmed';unset($this->s['account_authorization']);return 'recorded';
 }
}
$n=0;$ok=static function(bool $v)use(&$n){if(!$v)throw new RuntimeException('CONFIGURED_READER_OFFLINE_REFUSED');$n++;};
$cfg=['account'=>'fixture-account','configuration'=>'cfg_original_001','environment'=>'sandbox','generation'=>'1'];
$token='synthetic-fixture-token-not-credential';$leaseCalls=0;
$lease=static function(array $c)use(&$leaseCalls,$token){$leaseCalls++;return $c+['token'=>$token];};
$s=new StoreFixture;Wire::reset();$reader=Reader::forAttempt($s,'fixture-session','fixture-order',$cfg,$lease);
$ok($reader->fetchPayment('pay_ConfigFixture1')===null&&$leaseCalls===0&&Wire::$calls===0);
foreach(['account','configuration','environment','generation','extra','integer'] as $key){
 $bad=$cfg;if($key==='extra')$bad['extra']=true;elseif($key==='integer')$bad['generation']=1;else $bad[$key]=match($key){'environment'=>'production','generation'=>'2','configuration'=>'cfg_neighbor_001',default=>'neighbor-account'};
 $r=Reader::forAttempt($s,'fixture-session','fixture-order',$bad,$lease,true);$ok($r->fetchPayment('pay_ConfigFixture1')===null&&$leaseCalls===0&&Wire::$calls===0);
}
foreach([['other','fixture-order'],['fixture-session','neighbor']] as [$session,$order]){
 $r=Reader::forAttempt($s,$session,$order,$cfg,$lease,true);$ok($r->fetchPayment('pay_ConfigFixture1')===null&&$leaseCalls===0&&Wire::$calls===0);
}
$r=Reader::forAttempt($s,'fixture-session','fixture-order',$cfg,$lease,true);$ok($r->fetchPayment('pay_NeighborFixture')===null&&$leaseCalls===0&&Wire::$calls===0);
foreach(['account','configuration','environment','generation','token','extra','missing','exception'] as $key){
 $source=static function(array $c)use($key,$token){if($key==='exception')throw new RuntimeException('PRIVATE_FIXTURE');$v=$c+['token'=>$token];if($key==='extra')$v['extra']='x';elseif($key==='missing')unset($v['generation']);else $v[$key]=$key==='token'?'bad':'mismatch';return $v;};
 $r=Reader::forAttempt($s,'fixture-session','fixture-order',$cfg,$source,true);$ok($r->fetchPayment('pay_ConfigFixture1')===null&&Wire::$calls===0);
}
// Recheck immediately after credential resolution: reject before canonical transport creation.
$r=Reader::forAttempt($s,'fixture-session','fixture-order',$cfg,static function(array $c)use($s,$token){$s->s['account_authorization']['generation']='2';return $c+['token'=>$token];},true);
$ok($r->fetchPayment('pay_ConfigFixture1')===null&&Wire::$calls===0);
// Recheck after GET and at canonical service append boundary, using store double only.
foreach(['during','late'] as $phase){
 $s=new StoreFixture;Wire::reset();$r=Reader::forAttempt($s,'fixture-session','fixture-order',$cfg,$lease,true);
 $mutation=static function()use($s){$s->s['account_authorization']['generation']='3';};
 if($phase==='during')Wire::$during=$mutation;else $s->late=$mutation;
 $x=new FirstBriefingRelease($s,$r);$ok($x->attempt('fixture-session','fixture-order')==='unavailable'&&$s->writes===0&&Wire::$calls===1);
 // New generation requires fresh per-attempt reader/lease, never reuse previous configuration.
 Wire::$during=null;$s->late=null;$next=$cfg;$next['generation']='3';
 $x=new FirstBriefingRelease($s,Reader::forAttempt($s,'fixture-session','fixture-order',$next,$lease,true));
 $ok($x->attempt('fixture-session','fixture-order')==='briefing_available'&&$s->writes===1&&Wire::$calls===2);
 $calls=$leaseCalls;$ok($x->attempt('fixture-session','fixture-order')==='briefing_available'&&$s->writes===1&&Wire::$calls===2&&$leaseCalls===$calls);
}
$ok(Wire::$url==='https://api-sandbox.asaas.com/v3/payments/pay_ConfigFixture1');
$o=Wire::$options;$ok($o[CURLOPT_HTTPGET]===true&&$o[CURLOPT_SSL_VERIFYPEER]===true&&$o[CURLOPT_SSL_VERIFYHOST]===2&&$o[CURLOPT_FOLLOWLOCATION]===false&&$o[CURLOPT_PROXY]===''&&$o[CURLOPT_TIMEOUT]===7);
$ok(in_array('access_token: '.$token,$o[CURLOPT_HTTPHEADER],true));
ob_start();var_dump($r);$debug=ob_get_clean();$ok(!str_contains($debug,$token)&&!str_contains($debug,'fixture-session'));
try{serialize($r);$ok(false);}catch(RuntimeException){$ok(true);}
echo 'PASS CONFIGURED_SANDBOX_READER '.$n." CANONICAL_CLIENT_WITH_CURL_INTERCEPTION_MODEL_STORE_NO_NETWORK_NO_SQL\n";
