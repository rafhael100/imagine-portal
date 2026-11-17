<?php
declare(strict_types=1);
require __DIR__.'/../../vendor/autoload.php';require __DIR__.'/AtomicReader.php';
use App\Labs\OnepageAtomicRead\AtomicReader;
use Illuminate\Http\Request;use Illuminate\Session\{Store,ArraySessionHandler};
final class ReadStatement extends PDOStatement {
 public array $args=[];public function __construct(public mixed $row){}
 public function execute(?array $params=null):bool {$this->args=$params;return true;}
 public function fetchColumn(int $column=0):mixed{return json_encode($this->row);}
}
final class ReadPDO extends PDO {
 public array $queries=[];public ReadStatement $statement;
 public function __construct(){}public function inTransaction():bool{return false;}
 public function prepare(string $query,array $options=[]):PDOStatement|false{$this->queries[]=$query;return $this->statement;}
}
putenv('APP_ENV=testing');putenv('ONEPAGE_PENDING_ORDER_LAB=SYNTHETIC_ONLY');
$id='00000000-0000-0000-0000-000000000003';$p=new ReadPDO();$p->statement=new ReadStatement(['id'=>$id,'status'=>'pending_payment']);
$r=Request::create('/unregistered','GET',['buyer'=>'ignored','company'=>'ignored']);$s=new Store('lab',new ArraySessionHandler(120));$s->start();$s->put('portal.browser_auth',['phase'=>'business','reference'=>str_repeat('a',64)]);$r->setLaravelSession($s);
$n=0;$check=function(bool $b)use(&$n){if(!$b)throw new RuntimeException('CHECK_FAILED');$n++;};$attest=0;
$a=new AtomicReader($p,function()use(&$attest){$attest++;});$first=$a->read($r,$id);
$check($first['status']==='pending_payment'&&!$first['settled']&&!$first['preview_admitted']&&$first['binding']===null);
$check($p->queries===['SELECT onepage_buyer_lab.read_pending_order(?,?::uuid)']&&$p->statement->args===[hash('sha256',str_repeat('a',64)),$id]&&$attest===2);
$check($a->read($r,$id)===$first&&count($p->queries)===2&&$attest===4);
$p->statement->row=null;$check($a->read($r,$id)['status']==='denied');
$before=count($p->queries);$bad=new AtomicReader($p,fn()=>throw new RuntimeException('GUARD_DENIED'));$check($bad->read($r,$id)['status']==='denied'&&count($p->queries)===$before);
$p->statement->row=['id'=>$id,'status'=>'paid'];$check($a->read($r,$id)['status']==='denied');
$sql=file_get_contents(__DIR__.'/read.sql');$body=explode('END $$;',explode('AS $$',$sql)[1])[0];
$check(!preg_match('/\b(INSERT|UPDATE|DELETE|TRUNCATE|EXECUTE|accept_order)\b/i',$body));
$check(substr_count($body,'onepage_buyer_lab.principal(p_session)')===2&&str_contains($body,'company=actor.organization_id AND buyer=actor.identity_id'));
$check(substr_count($sql,'GRANT ')===1&&str_contains($sql,'GRANT EXECUTE ON FUNCTION onepage_buyer_lab.read_pending_order(text,uuid) TO onepage_buyer_runtime;'));
$check(!str_contains($body,'request_hash')&&!str_contains($body,'proof_key'));
echo "PASS $n incremental offline checks; SQL not executed\n";
