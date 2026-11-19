<?php
declare(strict_types=1);
use PrivateOnepageOneShot\{Bootstrap,LocalGate,OneShot,QualifiedLabContext};
use Illuminate\Database\Connection;
const SOURCE='/opt/onepage/runtime/application';
const VENDOR=SOURCE.'/vendor';
const WORKER='/opt/onepage/runtime/worker';
$phase='receipt';
try {
 if(PHP_SAPI!=='cli'||$argc!==2)throw new RuntimeException('MODE_REFUSED');
 $arg=$argv[1];$tmp=dirname($arg);
 if(!preg_match('~^/tmp/onepage-[A-Za-z0-9_-]+/receipt\.json$~D',$arg)||realpath($arg)!==$arg||is_link($arg)||is_link($tmp))throw new RuntimeException('RECEIPT_PATH_REFUSED');
 $st=lstat($arg);$dir=lstat($tmp);
 if(!is_array($st)||!is_array($dir)||($st['mode']&0777)!==0600||($dir['mode']&0777)!==0700||$st['nlink']!==1||$st['uid']!==$dir['uid']||$st['size']>2048||$st['uid']!==posix_geteuid())throw new RuntimeException('RECEIPT_MODE_REFUSED');
 $raw=file_get_contents($arg);clearstatcache(true,$arg);$now=lstat($arg);
 if(!is_string($raw)||!is_array($now)||$now['ino']!==$st['ino']||$now['dev']!==$st['dev']||strlen($raw)!==$st['size'])throw new RuntimeException('RECEIPT_CHANGED');
 $w=json_decode($raw,true,8,JSON_THROW_ON_ERROR);
 if(!is_array($w)||array_keys($w)!==['mode','systemIdentifier','dataDirectory','port','postmasterPid','started','expiresAt','nativeDatabase','inboxDatabase']
  ||($w['dataDirectory']??null)!==$tmp.'/data')throw new RuntimeException('RECEIPT_SHAPE_REFUSED');
 $phase='source';
 $manifest=json_decode(file_get_contents(__DIR__.'/inputs.json'),true,16,JSON_THROW_ON_ERROR);
 foreach($manifest as $file=>$pin)if(!is_file($file)||hash_file('sha256',$file)!==$pin)throw new RuntimeException('SOURCE_PIN_REFUSED');
 // Do not consume a repository .env or create Laravel cache files in a shared source checkout.
 foreach(['.env','.env.testing','bootstrap/cache/config.php'] as $f)if(file_exists(SOURCE.'/'.$f)||is_link(SOURCE.'/'.$f))throw new RuntimeException('SOURCE_ENV_REFUSED');
 foreach(['APP_ENV'=>'testing','APP_DEBUG'=>'false','LOG_CHANNEL'=>'null','CACHE_STORE'=>'array','SESSION_DRIVER'=>'array','QUEUE_CONNECTION'=>'sync','MAIL_MAILER'=>'array','COMPOSER_VENDOR_DIR'=>VENDOR,
  'APP_CONFIG_CACHE'=>$tmp.'/config.php','APP_SERVICES_CACHE'=>$tmp.'/services.php','APP_PACKAGES_CACHE'=>$tmp.'/packages.php','APP_EVENTS_CACHE'=>$tmp.'/events.php','APP_ROUTES_CACHE'=>$tmp.'/routes.php'] as $k=>$v){putenv($k.'='.$v);$_ENV[$k]=$v;$_SERVER[$k]=$v;}
 $loader=require VENDOR.'/autoload.php';
 $loader->setPsr4('App\\',[SOURCE.'/app']);
 $app=require SOURCE.'/bootstrap/app.php';
 mkdir($tmp.'/storage',0700);$app->useStoragePath($tmp.'/storage');
 $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
 if(!$app->environment('testing')||config('app.debug')!==false||$app->configurationIsCached())throw new RuntimeException('LARAVEL_MODE_REFUSED');
 require_once __DIR__.'/LocalGate.php';require_once __DIR__.'/Bootstrap.php';
 foreach(['Financial','PdoFinancialPort','Consumer','ServiceAuthorityPort','Worker'] as $f)require_once WORKER.'/'.$f.'.php';
 $invoke=require '/opt/onepage/runtime/oneshot/entry.php';
 $phase='gate';
 $g=new LocalGate($w);
 echo "PASS LOCAL_LARAVEL_AND_SQL_PRE_DDL\n";
 $phase='fixture';
 $seed=Bootstrap::setup($g);
 $native=$seed['native'];$inbox=$seed['inbox'];
 LocalGate::need((int)$native->query('SELECT count(*) FROM onepage_finance_lab.observations')->fetchColumn()===0&&(int)$native->query('SELECT count(*) FROM portal_onepage.entry_confirmations')->fetchColumn()===0);
 $r=['mode'=>'dedicated-disposable-synthetic','authorized'=>true,'catalogVerified'=>true,'sourcePin'=>OneShot::SOURCE_PIN,
  'expiresAt'=>$g->expiry(),'slots'=>[],'target'=>$seed['target'],'providerFixture'=>$seed['provider']];
 foreach(['inbox','finance','entry'] as $slot)$r['slots'][$slot]=['config'=>$g->config($slot),'sql'=>$g->read($slot)];
 $ctx=new class($g,$r) implements QualifiedLabContext {
  public function __construct(private LocalGate $g,private array $receipt){}
  public function currentReceipt():?array{
   if(time()>=$this->receipt['expiresAt'])return null;
   foreach(['inbox','finance','entry'] as $slot)if(!$this->g->current($slot))return null;
   return $this->receipt;
  }
  public function connection(string $slot):Connection{return $this->g->connection($slot);}
  public function effectiveConfiguration(string $slot):array{return $this->g->config($slot);}
 };
 $phase='oneshot';
 $result=$invoke($ctx);
 LocalGate::need($result===['financial'=>'completed','entry'=>'recorded']);
 $phase='replay';
 LocalGate::need($invoke($ctx)===['financial'=>'not_claimable','entry'=>'replay']);
 foreach(['native','inbox_admin'] as $s)LocalGate::need($g->current($s));
 $target=$seed['target'];
 foreach(['onepage_finance_lab.observations','portal_onepage.entry_confirmations'] as $table){
  LocalGate::need((int)$native->query('SELECT count(*) FROM '.$table)->fetchColumn()===1);
  $q=$native->prepare('SELECT evidence_sha256 FROM '.$table.' WHERE order_id=?::uuid AND account_ref=? AND payment_id=? AND environment=\'sandbox\'');
  $q->execute([$target['order'],$target['nativeAccount'],$target['payment']]);$digest=$q->fetchColumn();
  LocalGate::need(is_string($digest)&&preg_match('/^[a-f0-9]{64}$/D',$digest)===1&&(!isset($match)||$match===$digest));$match=$digest;
 }
 $q=$inbox->prepare('SELECT state FROM portal_psp.asaas_inbox WHERE account_ref=?::uuid AND event_id=? AND payment_id=?');
 $q->execute([$target['account'],$target['event'],$target['payment']]);LocalGate::need($q->fetchColumn()==='observed_psp_received');
 LocalGate::need(!$seed['auth']->isActiveBusinessSession($seed['session']));
 echo "PASS ONE_SHOT_SAME_ORDER_OBSERVATION_AND_CONFIRMATION\n";
} catch(Throwable $e) {
 // No SQL/provider data is printed. Type/phase suffice for normal diagnostics.
 fwrite(STDERR,'LOCAL_HARNESS_REFUSED phase='.$phase.' type='.get_class($e).' line='.$e->getLine()."\n");exit(1);
}
