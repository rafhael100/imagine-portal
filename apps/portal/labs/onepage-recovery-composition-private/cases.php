<?php
declare(strict_types=1);
require_once __DIR__.'/../onepage-buyer/CoreSchema.php';
use App\Labs\OnepageBuyer\Guard;
use App\Labs\OnepageAtomicRead\AtomicReader;
use Imagine\Lab\OnepageBindingProducer\ProducerContract;
require_once __DIR__.'/../onepage-atomic-read/AtomicReader.php';
require_once __DIR__.'/../onepage-binding-producer/ProducerContract.php';
require_once __DIR__.'/first-attempt.php';
require_once __DIR__.'/ChildProcess.php';
function checkRecoveryComposition($db,PDO $runtime,PDO $owner,array $identity,Closure $pinned,
 $own,$neighbor,string $order,string $cluster,string $control,string $ddl):void{
 if(getenv('ONEPAGE_PENDING_ORDER_LAB')!=='SYNTHETIC_ONLY')throw new RuntimeException('LAB_ONLY');
 $check=static function(bool $v):void{if(!$v)throw new RuntimeException('RECOVERY_COMPOSITION_REFUSED');};
 $pinned();Guard::runtime($runtime,$owner,$identity,'onepage_buyer_runtime');
 $db->unprepared(\App\Labs\OnepageBuyer\CoreSchema::read(__DIR__.'/../onepage-atomic-read/read.sql'));
 $first=AtomicReader::guarded($runtime,$owner,$identity,$pinned)->read($own,$order);
 $plan=ProducerContract::prepare($first,'lab-cas-fixture','cus_LABreserve');$check($plan!==null);
 $scope=array_diff_key($first['snapshot'],array_flip(['buyer','status','quote_snapshot','terms','contract_text']));
 $proof=hash('sha256',$own->session()->get('portal.browser_auth')['reference']);
 $other=hash('sha256',$neighbor->session()->get('portal.browser_auth')['reference']);
 $pinned();$db->unprepared(file_get_contents($ddl));
 $connect=static function()use($pinned,$owner,$identity):PDO{
  $pinned();$p=new PDO('pgsql:host=127.0.0.1;port=55439;dbname=portal_onepage_buyer_ci','onepage_buyer_runtime','',
   [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
  Guard::runtime($p,$owner,$identity,'onepage_buyer_runtime');return $p;
 };
 $reader=$connect();$line=$plan->lines['setup-1'];$pinned();
 $q=$owner->prepare('INSERT INTO onepage_buyer_lab.fixture_charge_reservations
  (order_id,line,contract_scope,account,environment,customer,reference,due_date,amount_cents)
  VALUES(CAST(? AS uuid),?,CAST(? AS jsonb),?,?,?,?,CAST(? AS date),?)');
 $owner->beginTransaction();try{
  foreach($plan->lines as $part)$q->execute([$order,$part['key'],json_encode($scope,JSON_THROW_ON_ERROR),$plan->scope['account'],
   'sandbox','cus_LABreserve',$part['external_reference'],$part['due_date'],$part['amount_cents']]);
  $owner->commit();
 }finally{if($owner->inTransaction())$owner->rollBack();}
 $provider=['account'=>$plan->scope['account'],'rows'=>[]];$sends=0;
 $transport=static function(array $sent)use(&$provider,&$sends,$line,$check):void{
  $check($sent===$line);++$sends;
  $provider['rows'][]=['object'=>'payment','id'=>'pay_LABrecoverEntry','customer'=>'cus_LABreserve',
   'externalReference'=>$sent['external_reference'],'currency'=>'BRL',
   'value'=>intdiv($sent['amount_cents'],100).'.'.str_pad((string)($sent['amount_cents']%100),2,'0',STR_PAD_LEFT),
   'dueDate'=>$sent['due_date'],'status'=>'PENDING','deleted'=>false,'installment'=>null,'installmentNumber'=>null,'subscription'=>null];
  throw new RuntimeException('FAKE_RESPONSE_LOST_AFTER_CREATE');
 };
 $attempt=static fn(?Closure $pause=null)=>runFixtureFirstAttempt($runtime,$reader,$owner,$identity,$pinned,$plan,$proof,'setup-1',$transport,$pause);
 $check($attempt(static function()use(&$sends,$check):void{$check($sends===0);throw new RuntimeException('FAKE_ABORT');})==='denied'&&$sends===0&&$provider['rows']===[]);
 echo "PASS RECOVERY_ABORT_BEFORE_SEND\n";
 $check($attempt()==='reconcile_only'&&$sends===1&&count($provider['rows'])===1);
 $check($attempt()==='reconcile_only'&&$sends===1);
 echo "PASS RECOVERY_LOST_RESPONSE_ONE_SEND\n";
 $recover=static function(array $state)use($cluster,$control,$identity,$proof,$order,$check):array{
  $cmd=[PHP_BINARY,'-n','-d','memory_limit=512M'];
  foreach(['pdo','pdo_pgsql','mbstring','dom','xml','xmlreader','xmlwriter','simplexml','tokenizer','ctype','fileinfo','phar','iconv','sodium'] as $module){
   $file=ini_get('extension_dir').'/'.$module.'.so';if(is_file($file)){$cmd[]='-d';$cmd[]='extension='.$file;}}
  $cmd[]=__DIR__.'/recover-worker.php';
  $input=json_encode(['cluster'=>$cluster,'control'=>$control,'identity'=>$identity,'proof'=>$proof,'order'=>$order,'provider'=>$state],JSON_THROW_ON_ERROR);
  $out=json_decode(RecoveryChildProcess::run($cmd,$input),true,32,JSON_THROW_ON_ERROR);
  $check(is_int($out['pid']??null)&&$out['pid']!==getmypid()&&$out['lists']===1);return $out;
 };
 $bind=static function(PDO $p,string $session)use($order):string{
  $q=$p->prepare("SELECT onepage_buyer_lab.fixture_bind_charge(?,CAST(? AS uuid),'setup-1')");$q->execute([$session,$order]);return $q->fetchColumn();};
 $read=static function(PDO $p,string $session)use($order):?array{
  $q=$p->prepare("SELECT onepage_buyer_lab.fixture_read_bound_charge(?,CAST(? AS uuid),'setup-1')");$q->execute([$session,$order]);return json_decode($q->fetchColumn()?:'null',true,32,JSON_THROW_ON_ERROR);};
 $missing=$provider;$missing['rows']=[];$out=$recover($missing);
 $check($out['code']==='not_found_yet'&&$out['candidate']===null&&$out['details']===0&&$bind($runtime,$proof)==='pending'&&$read($reader,$proof)===null);
 $ambiguous=$provider;$ambiguous['rows'][]=array_replace($provider['rows'][0],['id'=>'pay_LABambiguous']);$out=$recover($ambiguous);
 $check($out['code']==='conflict_or_unavailable'&&$out['candidate']===null&&$out['details']===0&&$read($reader,$proof)===null&&$sends===1);
 echo "PASS RECOVERY_ABSENT_AMBIGUOUS_CLOSED\n";
 $out=$recover($provider);$candidate=$out['candidate'];
 $check($out['code']==='linked_candidate'&&$out['details']===1&&$candidate===['order'=>$order,'line'=>'setup-1','account'=>$plan->scope['account'],
  'externalReference'=>$line['external_reference'],'paymentId'=>'pay_LABrecoverEntry']);
 // Test bootstrap stages verified fake evidence, never buyer JSON. Only existing SQL writes binding.
 $pinned();$q=$owner->prepare('INSERT INTO onepage_buyer_lab.fixture_binding_witnesses
  (probe,order_id,line,contract_scope,account,environment,customer,reference,currency,amount_cents,due_date,payment_id)
  VALUES(?,CAST(? AS uuid),?,CAST(? AS jsonb),?,?,?,?,?,?,CAST(? AS date),?)');
 $q->execute(['recovered-entry',$order,'setup-1',json_encode($scope,JSON_THROW_ON_ERROR),$candidate['account'],'sandbox','cus_LABreserve',
  $candidate['externalReference'],'BRL',$line['amount_cents'],$line['due_date'],$candidate['paymentId']]);
 $check($bind($runtime,$other)==='denied');
 $acl=$owner->query("SELECT has_table_privilege('onepage_buyer_runtime','onepage_buyer_lab.fixture_charge_bindings','INSERT,UPDATE,DELETE')
  OR has_table_privilege('onepage_buyer_runtime','onepage_buyer_lab.fixture_binding_witnesses','INSERT,UPDATE,DELETE')")->fetchColumn();
 $check(in_array($acl,[false,'f',0,'0'],true));
 $runtime->beginTransaction();try{$check($bind($runtime,$proof)==='linked'&&$read($reader,$proof)===null);$runtime->rollBack();}
 finally{if($runtime->inTransaction())$runtime->rollBack();}
 $check($read($connect(),$proof)===null);echo "PASS RECOVERY_BIND_ROLLBACK_INVISIBLE\n";
 Guard::runtime($runtime,$owner,$identity,'onepage_buyer_runtime');$pinned();
 $runtime->beginTransaction();try{$check($bind($runtime,$proof)==='linked'&&$read($reader,$proof)===null);$runtime->commit();}
 finally{if($runtime->inTransaction())$runtime->rollBack();}
 $fresh=$connect();$bound=$read($fresh,$proof);
 $check($bound!==null&&$bound['payment_id']===$candidate['paymentId']&&$bound['scope']['order_id']===$order&&$bound['scope']['reference']===$line['external_reference']
  &&$read($fresh,$other)===null&&$bind($fresh,$proof)==='replay'&&$read($fresh,$proof)===$bound);
 $out=$recover($provider);$check($out['code']==='linked_candidate'&&$out['details']===1&&$out['candidate']===$candidate);
 $conflict=$provider;$conflict['rows'][0]['id']='pay_LABwrongId';$out=$recover($conflict);
 $check($out['code']==='conflict_or_unavailable'&&$out['candidate']===null&&$out['details']===0);
 $pinned();$owner->exec("UPDATE onepage_buyer_lab.fixture_binding_witnesses SET payment_id='pay_LABwrongId' WHERE probe='recovered-entry'");
 $check($bind($fresh,$proof)==='conflict'&&$read($fresh,$proof)===$bound);
 $check($sends===1&&$attempt()==='reconcile_only'&&$sends===1);
 $check(AtomicReader::guarded($fresh,$owner,$identity,$pinned)->read($own,$order)===$first);
 $check($first['status']==='pending_payment'&&$first['settled']===false&&$first['preview_admitted']===false);
 echo "PASS RECOVERY_COMMIT_FRESH_REPLAY_CONFLICT\n";
 echo "PASS RECOVERY_PENDING_NO_RELEASE\n";
}
