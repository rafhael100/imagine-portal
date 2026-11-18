<?php
declare(strict_types=1);
require __DIR__.'/../onepage-pending-order/ProcessPreflight.php';
require __DIR__.'/../onepage-buyer/Guard.php';
require __DIR__.'/Recovery.php';
use App\Labs\OnepageBuyer\Guard;
use Imagine\Lab\OnepageSandboxRecovery\{Recovery,SandboxReads,ScopedRead};
try{
 App\Labs\OnepagePendingOrder\ProcessPreflight::assertClean();Guard::offline();
 if(getenv('ONEPAGE_PENDING_ORDER_LAB')!=='SYNTHETIC_ONLY')throw new RuntimeException('LAB_ONLY');
 $raw=stream_get_contents(STDIN,65537);if(strlen($raw)>65536)throw new RuntimeException('INPUT_LIMIT');
 $in=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
 require __DIR__.'/../../vendor/autoload.php';$app=require __DIR__.'/../../bootstrap/app.php';
 $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
 Guard::configuration($app->environment(),config('database.default'),config('database.connections.pgsql'));
 $db=$app['db']->connection('pgsql');Guard::configuration($app->environment(),config('database.default'),$db->getConfig());
 $owner=$db->getPdo();$identity=Guard::identity($owner,$in['cluster'],$in['control']);
 if($identity!==$in['identity'])throw new RuntimeException('CLUSTER_DRIFT');
 $p=new PDO('pgsql:host=127.0.0.1;port=55439;dbname=portal_onepage_buyer_ci','onepage_buyer_runtime','',
  [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
 Guard::runtime($p,$owner,$identity,'onepage_buyer_runtime');
 $q=$p->prepare('SELECT onepage_buyer_lab.fixture_read_attempt_plan(?,CAST(? AS uuid))');$q->execute([$in['proof'],$in['order']]);
 $rows=json_decode($q->fetchColumn()?:'null',true,32,JSON_THROW_ON_ERROR);$entry=null;
 foreach($rows??[] as $row)if($row['key']==='setup-1'){if($entry!==null)throw new RuntimeException('AMBIGUOUS_PLAN');$entry=$row;}
 if($entry===null)throw new RuntimeException('PLAN_REQUIRED');
 $q=$p->prepare("SELECT onepage_buyer_lab.fixture_read_bound_charge(?,CAST(? AS uuid),'setup-1')");$q->execute([$in['proof'],$in['order']]);
 $bound=json_decode($q->fetchColumn()?:'null',true,32,JSON_THROW_ON_ERROR);
 $reserved=['order_id'=>$in['order'],'line'=>'setup-1','account'=>$entry['account'],'environment'=>$entry['environment'],
  'customer'=>$entry['customer'],'reference'=>$entry['external_reference'],'due_date'=>$entry['due_date'],
  'amount_cents'=>$entry['amount_cents'],'attempted'=>$entry['attempted'],'payment_id'=>$bound['payment_id']??null];
 $reads=new class($in['provider']) implements SandboxReads{
  public int $lists=0;public int $details=0;
  public function __construct(private array $state){}
  public function listByExternalReference(string $reference):ScopedRead{
   ++$this->lists;$rows=array_values(array_filter($this->state['rows'],static fn($p)=>$p['externalReference']===$reference));
   return new ScopedRead($this->state['account'],'sandbox',['object'=>'list','hasMore'=>false,'offset'=>0,'totalCount'=>count($rows),'data'=>$rows]);
  }
  public function paymentById(string $id):ScopedRead{
   ++$this->details;$rows=array_values(array_filter($this->state['rows'],static fn($p)=>$p['id']===$id));
   if(count($rows)!==1)throw new RuntimeException('DETAIL_CONFLICT');return new ScopedRead($this->state['account'],'sandbox',$rows[0]);
  }
 };
 $result=Recovery::inspect($reserved,$reads);Guard::runtime($p,$owner,$identity,'onepage_buyer_runtime');
 Guard::configuration($app->environment(),config('database.default'),$db->getConfig());
 if(Guard::identity($owner,$in['cluster'],$in['control'])!==$identity)throw new RuntimeException('CLUSTER_DRIFT');
 $c=$result->linkedCandidate();
 echo json_encode(['code'=>$result->code->value,'candidate'=>$c===null?null:get_object_vars($c),'lists'=>$reads->lists,'details'=>$reads->details,'pid'=>getmypid()],JSON_THROW_ON_ERROR);
}catch(Throwable){fwrite(STDERR,"RECOVERY_CHILD_REFUSED\n");exit(1);}
