<?php
/** Proposed PG acceptance: requires an already prepared fresh #922 disposable cluster.
 * DO NOT run in socket-blocked executor. No startup, migration or transport fallback. */
declare(strict_types=1);
require __DIR__.'/ProcessPreflight.php';
App\Labs\OnepagePendingOrder\ProcessPreflight::assertClean();
require __DIR__.'/../../vendor/autoload.php';
require __DIR__.'/../onepage-buyer/Guard.php';
require __DIR__.'/../onepage-buyer/BuyerQuoteAdapter.php';
require __DIR__.'/Adapter.php';
require __DIR__.'/ProofChecks.php';
use App\Labs\OnepageBuyer\{Guard,BuyerQuoteAdapter};
use App\Labs\OnepagePendingOrder\Adapter;
use App\Infrastructure\Identity\PostgresPortalBrowserAuthentication;
use App\Domain\Identity\Security\{PortalPasswordCredential,PortalTotpSecretCipher};
use Illuminate\Http\Request;
use Illuminate\Session\{Store,ArraySessionHandler};
function requestOrder(?string $reference,string $phase='business'):Request {
    $r=Request::create('/unregistered','POST');$s=new Store('order-lab',new ArraySessionHandler(120));$s->start();
    if($reference!==null)$s->put('portal.browser_auth',['phase'=>$phase,'reference'=>$reference]);$r->setLaravelSession($s);return $r;
}
$checks=[];
function orderCheck(bool $ok,string $name):void {global $checks;if(!$ok)throw new RuntimeException('FAILED: '.$name);$checks[]=$name;echo 'PASS ORDER_CASE: '.$name.PHP_EOL;}
try {
 if($argc!==3||getenv('ONEPAGE_PENDING_ORDER_LAB')!=='SYNTHETIC_ONLY')throw new RuntimeException('LAB_ONLY');
 Guard::offline();$app=require __DIR__.'/../../bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
 Guard::configuration($app->environment(),config('database.default'),config('database.connections.pgsql'));
 $db=$app['db']->connection('pgsql');Guard::configuration($app->environment(),config('database.default'),$db->getConfig());
 $owner=$db->getPdo();$identity=Guard::identity($owner,$argv[1],$argv[2]);$config=serialize(config('database.connections.pgsql'));
 $db->setReconnector(static function(){throw new RuntimeException('NO_RECONNECT');});
 $barrier=function()use($db,$owner,$identity,$argv,$config){
  Guard::configuration(app()->environment(),config('database.default'),$db->getConfig());
  if($db->getPdo()!==$owner||serialize(config('database.connections.pgsql'))!==$config||Guard::identity($owner,$argv[1],$argv[2])!==$identity)throw new RuntimeException('IDENTITY_CHANGED');
 };
 $db->beforeExecuting(static function()use($barrier){$barrier();});
 echo "PASS ORDER_PREFLIGHT_LARAVEL_SQL_ATTESTED\n";
 $key=random_bytes(32);$secret='GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';$password='synthetic-order-password';
 $hash=PortalPasswordCredential::makeHash($password);$cipher=PortalTotpSecretCipher::seal($secret,$key);Guard::fixtures($hash,$cipher,$key,$secret,$password);
 // All preparation below occurs only after effective Laravel and SQL identity attestation.
 if($db->selectOne("SELECT to_regclass('onepage_buyer_lab.pending_orders') r")->r!==null)throw new RuntimeException('FRESH_ORDER_LAB_REQUIRED');
 $proof=$db->selectOne('SELECT secret FROM portal_browser_private.proof_key')->secret;
 if(!is_string($proof)||$proof==='')throw new RuntimeException('BASE_AUTH_FIXTURE_REQUIRED');
 $db->unprepared(file_get_contents(__DIR__.'/store.sql'));
 $connect=function(string $role)use($barrier,$owner,$identity):PDO {
  if(!in_array($role,['portal_browser_runtime','onepage_buyer_runtime'],true))throw new RuntimeException('ROLE_REQUIRED');
  $barrier();$p=new PDO('pgsql:host=127.0.0.1;port=55439;dbname=portal_onepage_buyer_ci',$role,'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
  Guard::runtime($p,$owner,$identity,$role);$barrier();return $p;
 };
 $auth=new PostgresPortalBrowserAuthentication($connect('portal_browser_runtime'),'portal_onepage_buyer_ci',$proof,$key,bin2hex(random_bytes(32)));
 $runtime=$connect('onepage_buyer_runtime');$quotes=new BuyerQuoteAdapter($runtime);$orders=new Adapter($runtime);
 $companies=[];foreach(['a','b'] as $c){$id=$db->selectOne('SELECT gen_random_uuid() id')->id;$companies[$c]=$id;$db->insert("INSERT INTO portal_organizations(id,reference,display_name,status,security_revision) VALUES(?,?,?,'active',1)",[$id,'lab-order-'.$c,'Synthetic orders']);}
 $actors=[];$sessions=[];$pending=[];
 foreach(['a','same','other','novinc','manager'] as $name){
  $id=$db->selectOne('SELECT gen_random_uuid() id')->id;$actors[$name]=$id;$company=$companies[$name==='other'?'b':'a'];$email='order-'.$name.'@example.invalid';
  $db->insert("INSERT INTO portal_identities(id,email_normalized,status,password_hash,mfa_secret_ciphertext,email_verified_at,mfa_confirmed_at,security_revision) VALUES(?,?,'active',?,?,clock_timestamp()-interval '1 minute',clock_timestamp()-interval '1 minute',1)",[$id,$email,$hash,$cipher]);
  if($name!=='novinc')$db->insert("INSERT INTO portal_memberships(id,identity_id,organization_id,role,status,membership_revision) VALUES(gen_random_uuid(),?,?,?,'active',1)",[$id,$company,$name==='manager'?'manager':'member']);
  $p=$auth->beginPassword($email,$password,$company,'192.0.2.20');
  if($name==='novinc'){orderCheck($p===null,'no membership cannot authenticate');continue;}
  orderCheck(is_string($p),'native password '.$name);$pending[$name]=$p;
  $n=(int)$db->selectOne('SELECT floor(extract(epoch FROM clock_timestamp())/30)::bigint n')->n;
  $h=hash_hmac('sha1',pack('N2',intdiv($n,4294967296),$n%4294967296),'12345678901234567890',true);$offset=ord($h[19])&15;
  $code=str_pad((string)((unpack('N',substr($h,$offset,4))[1]&0x7fffffff)%1000000),6,'0',STR_PAD_LEFT);
  $sessions[$name]=$auth->completeMfa($p,$code,'192.0.2.20');orderCheck(is_string($sessions[$name]),'native MFA '.$name);
 }
 $a=requestOrder($sessions['a']);$selection=['site'=>'onepage','channels'=>[],'tier'=>'minimum'];
 $draft=$quotes->create($a,'lab-order-quote',$selection);orderCheck($draft['status']==='draft','own persisted quote');$id=$draft['id'];
 // Server-owned synthetic contract text and terms, not a legally approved offer/policy.
 $db->insert("INSERT INTO onepage_buyer_lab.order_terms(quote_id,quote_snapshot,contract_ref,contract_revision,contract_text,terms) SELECT id,quote,'lab-onepage-contract',1,'Synthetic contract v1: pending only; no payment or Sites authority.',?::jsonb FROM onepage_buyer_lab.drafts WHERE id=?",[json_encode(['scope'=>'synthetic_only','payment_policy'=>'undecided']),$id]);
 $review=$orders->review($a,$id)['review'];$accept=['accepted'=>true]+array_intersect_key($review,array_flip(['quote_id','offer_digest','terms_digest','contract_ref','contract_revision','contract_digest']));
 foreach([requestOrder(null),requestOrder($pending['a'],'pending_mfa'),requestOrder($sessions['same']),requestOrder($sessions['other']),requestOrder($sessions['manager'])] as $i=>$r)
  orderCheck($orders->accept($r,'order-key',$id,$accept)===['status'=>'denied'],'authority/ownership denial '.$i);
 foreach(['accepted'=>false,'offer_digest'=>str_repeat('0',64),'terms_digest'=>str_repeat('0',64),'contract_revision'=>2,'contract_digest'=>str_repeat('0',64)] as $k=>$v)
  orderCheck($orders->accept($a,'order-key',$id,array_replace($accept,[$k=>$v]))===['status'=>'invalid_acceptance'],'acceptance mismatch '.$k);
 orderCheck((int)$db->selectOne('SELECT count(*) n FROM onepage_buyer_lab.pending_orders')->n===0,'all initial denials write zero');
 $before=microtime(true);$first=$orders->accept($a,'order-key',$id,$accept);$after=microtime(true);
 orderCheck($first['status']==='pending_payment'&&!$first['payment_requested']&&!$first['site_provisioned'],'pending only');
 $at=DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.u\Z',$first['accepted_at'],new DateTimeZone('UTC'));
 orderCheck($at!==false&&str_ends_with($first['accepted_at'],'Z')&&(float)$at->format('U.u')>=$before-1&&(float)$at->format('U.u')<=$after+1,'server UTC acceptance timestamp');
 $fresh=new Adapter($connect('onepage_buyer_runtime'));orderCheck($fresh->accept($a,'order-key',$id,$accept)===$first,'same order and timestamp after new connection');
 orderCheck($fresh->accept($a,'different-key',$id,$accept)===['status'=>'conflict'],'same quote cannot create another order');
 orderCheck($fresh->accept($a,'order-key',$id,array_replace($accept,['contract_revision'=>2]))===['status'=>'invalid_acceptance'],'changed replay refused');
 $stored=$db->selectOne('SELECT * FROM onepage_buyer_lab.pending_orders WHERE quote_id=?',[$id]);
 orderCheck($stored->buyer===$actors['a']&&$stored->company===$companies['a']&&(int)$stored->contract_revision===1,'server actor company contract frozen');
 $snapshot=function(string $table)use($db):string {
  if(!in_array($table,['order_terms','pending_orders'],true))throw new RuntimeException('INVALID_TABLE');
  return json_encode($db->select("SELECT to_jsonb(t)::text snapshot FROM onepage_buyer_lab.$table t ORDER BY quote_id"),JSON_THROW_ON_ERROR);
 };
 foreach(['order_terms','pending_orders'] as $table){
  $beforeSnapshot=$snapshot($table);$info=[];
  try{$db->statement("UPDATE onepage_buyer_lab.$table SET contract_revision=2");}
  catch(Illuminate\Database\QueryException $e){$info=$e->errorInfo??[];}
  orderCheck(App\Labs\OnepagePendingOrder\ProofChecks::immutableRefusal($info,$beforeSnapshot,$snapshot($table)),'immutable P0001 exact marker and snapshot '.$table);
 }
 // A negative expired session only; positive sessions above are native-issued.
 $expired=str_repeat('f',64);
 $db->insert("INSERT INTO portal_auth_sessions(id,session_sha256,identity_id,organization_id,status,session_purpose,identity_revision,membership_revision,organization_revision,issued_at,expires_at,mfa_verified_at) VALUES(gen_random_uuid(),?,?,?,'active','business',1,1,1,clock_timestamp()-interval '2 hours',clock_timestamp()-interval '1 hour',clock_timestamp()-interval '2 hours')",[hash('sha256',$expired),$actors['a'],$companies['a']]);
 orderCheck($fresh->accept(requestOrder($expired),'order-key',$id,$accept)===['status'=>'denied'],'expired session denied');
 // Separate revocation control: buyer A must still be genuinely active first.
 $sessionRow=fn()=>$db->selectOne('SELECT status,revoked_at FROM portal_auth_sessions WHERE session_sha256=?',[hash('sha256',$sessions['a'])]);
 $beforeRow=$sessionRow();
 orderCheck($beforeRow->status==='active'&&$beforeRow->revoked_at===null&&$auth->isActiveBusinessSession($sessions['a']),'revocation control active native session');
 orderCheck($fresh->accept($a,'order-key',$id,$accept)===$first,'revocation control still authorized');
 $beforeSnapshot=$snapshot('pending_orders');
 orderCheck($auth->revoke($sessions['a']),'native revoke call returned');
 $afterRow=$sessionRow();
 orderCheck(App\Labs\OnepagePendingOrder\ProofChecks::revokedTransition((array)$beforeRow,(array)$afterRow)&&!$auth->isActiveBusinessSession($sessions['a']),'session SQL transitioned active to revoked');
 orderCheck($fresh->accept($a,'order-key',$id,$accept)===['status'=>'denied'],'revoked replay denied');
 orderCheck($fresh->accept($a,'new-key-after-revoke',$id,$accept)===['status'=>'denied'],'revoked creation denied');
 orderCheck($snapshot('pending_orders')===$beforeSnapshot,'revocation denials leave exact order snapshot unchanged');
 // Independent buyer owns their own quote; suspension cannot be confused with ownership denial.
 $same=requestOrder($sessions['same']);$otherDraft=$quotes->create($same,'suspension-control',$selection);$otherId=$otherDraft['id'];
 orderCheck($otherDraft['status']==='draft','suspension control own quote');
 $db->insert("INSERT INTO onepage_buyer_lab.order_terms SELECT id,quote,'lab-suspension-contract',1,'Synthetic suspension control contract.',?::jsonb FROM onepage_buyer_lab.drafts WHERE id=?",[json_encode(['scope'=>'synthetic_only']),$otherId]);
 $otherReview=$fresh->review($same,$otherId);orderCheck($otherReview['status']==='review'&&$auth->isActiveBusinessSession($sessions['same']),'suspension control authorized before change');
 $otherAccept=['accepted'=>true]+array_intersect_key($otherReview['review'],array_flip(['quote_id','offer_digest','terms_digest','contract_ref','contract_revision','contract_digest']));
 $db->update("UPDATE portal_memberships SET status='suspended' WHERE identity_id=?",[$actors['same']]);
 orderCheck($fresh->accept($same,'suspension-key',$otherId,$otherAccept)===['status'=>'denied'],'suspended owner membership denied');
 orderCheck($snapshot('pending_orders')===$beforeSnapshot,'suspension denial writes nothing');
 orderCheck((int)$db->selectOne('SELECT count(*) n FROM onepage_buyer_lab.pending_orders')->n===1,'one immutable pending order only');
 echo json_encode(['status'=>'PASS','checks'=>$checks,'scope'=>'synthetic laboratory','psp_calls'=>0],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
}catch(Throwable $e){fwrite(STDERR,'ORDER_LAB_FAILED: '.($e instanceof PDOException||$e instanceof Illuminate\Database\QueryException?'SQL_FAILURE':$e->getMessage()).PHP_EOL);exit(1);}
