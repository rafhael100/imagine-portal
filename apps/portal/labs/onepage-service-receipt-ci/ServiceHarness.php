<?php
declare(strict_types=1);
namespace PrivateServiceReceipt;
use PDO;use RuntimeException;
/** Functional disposable-service test. Not the prior exact-byte P20 environment. */
final class ServiceHarness {
 private static function need(bool $v):void{if(!$v)throw new RuntimeException('SERVICE_HARNESS_REFUSED');}
 public static function prepareInbox(ServiceGate $g):PDO{
  $a=$g->native();$g->beforeDdl($a);
  $a->exec('CREATE DATABASE portal_asaas_ci');
  $p=$g->connect('portal_native_test_bootstrap','portal_asaas_ci');$g->assertEmpty($p,'portal_asaas_ci');
  self::need((int)$p->query("SELECT count(*) FROM pg_namespace WHERE nspname !~ '^pg_' AND nspname NOT IN ('public','information_schema')")->fetchColumn()===0);
  self::need((int)$p->query("SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname !~ '^pg_' AND n.nspname<>'information_schema'")->fetchColumn()===0);
  $g->beforeDdl($p);
  $p->exec('CREATE ROLE postgres NOLOGIN NOSUPERUSER NOINHERIT NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS');
  $dir=__DIR__.'/../onepage-dual-inbox';
  foreach(['inbox.sql'=>'292b23f7416e34e9b6f6b8ebb9dff6d056426f4b','lease.sql'=>'03da8af90eca8733fb1842498fd4ac6b50fe8d7d'] as $f=>$sha){
   $raw=file_get_contents($dir.'/'.$f);self::need(sha1('blob '.strlen($raw)."\0".$raw)===$sha);
   self::need(substr_count($raw,"\\set ON_ERROR_STOP on\n")===1);
   $sql=str_replace("\\set ON_ERROR_STOP on\n",'', $raw);
   self::need(!preg_match('/^\s*\\\\/m',$sql));$g->beforeDdl($p);$p->exec($sql);
  }
  self::need((int)$p->query('SELECT count(*) FROM portal_psp.asaas_inbox')->fetchColumn()===0);return $p;
 }
 public static function run(ServiceGate $g,array $ctx,?\Closure $continuation=null):void{
  $native=$ctx['native'];$inbox=$ctx['inbox'];$dir=__DIR__.'/../onepage-dual-inbox';$finance=__DIR__.'/../onepage-finance-integrated';
  $sql=file_get_contents($finance.'/finance.sql');self::need(hash('sha256',$sql)==='da176ef9c16e5d32059456bccedbf344cfdc7dc5a6c6aad60c597feaab6e4c91');
  $g->beforeDdl($native);$native->exec($sql);$g->beforeDdl($native);$native->exec('ALTER ROLE onepage_finance_lab_verifier LOGIN');
  require_once $finance.'/Financial.php';require_once __DIR__.'/ServiceFinancialPort.php';
  foreach(['Consumer.php','Cases.php','Ingress.php','SharedInboxPort.php','SharedCatalog.php','CaptureHarness.php','PgFixture.php'] as $f)require_once $dir.'/'.$f;
  $fragments=[];foreach(['pre.sql','functions.sql','post.sql'] as $f)$fragments[$f]=file_get_contents($dir.'/shared-'.$f);
  $expected=\PrivateSharedIngress\SharedCatalog::expected($fragments);
  $g->beforeDdl($inbox);self::need($inbox->beginTransaction());
  try{
   $inbox->exec("SET LOCAL lock_timeout='2s'; SET LOCAL statement_timeout='3s'");
   foreach($fragments as $sql)$inbox->exec($sql);
   \PrivateSharedIngress\SharedCatalog::matches($expected,json_decode($inbox->query(file_get_contents($dir.'/delta.sql'))->fetchColumn(),true,64,JSON_THROW_ON_ERROR));
   self::auditRoles($inbox);self::need($inbox->commit());
  }catch(\Throwable $e){if($inbox->inTransaction())$inbox->rollBack();throw $e;}
  fwrite(STDOUT,"PASS SERVICE_SHARED_SCHEMA_COMMITTED\n");
  $ports=['registrar'=>$ctx['fixture']['registrar'],'native_runtime'=>$ctx['runtime']];
  foreach(['inbox_a'=>['asaas_reconcile_worker','portal_asaas_ci'],'inbox_b'=>['asaas_reconcile_worker','portal_asaas_ci'],'inbox_audit'=>['portal_native_test_bootstrap','portal_asaas_ci'],'native_audit'=>['portal_native_test_bootstrap','portal_native_orders_ci'],'financial'=>['onepage_finance_lab_verifier','portal_native_orders_ci'],'ingress_capture'=>['onepage_ingress_capture','portal_asaas_ci']] as $n=>[$role,$db])$ports[$n]=$g->connect($role,$db);
  $gate=static function(string $name,PDO $p)use($g,$ports):bool{self::need(($ports[$name]??null)===$p);$g->assertSession($p);return true;};
  $f=new \PrivateD0IntegratedLab\ServiceFinancialPort($ports['financial'],static fn(PDO $p):bool=>$gate('financial',$p),['database'=>'portal_native_orders_ci','port'=>5432,'server_address'=>$g->address()]);
  $capture=new \PrivateSharedIngress\CaptureHarness($ports['inbox_audit'],$ports['ingress_capture'],$gate,$f);
  $fixture=new \PrivateD0DualDbCases\PgFixture($ctx,$ports,$gate,$f,$capture);
  self::need(\PrivateD0DualDbCases\cases($fixture)===['reap_skip_lock','claim_skip_lock','expired_ack_and_alias','lost_financial_result']);
  $g->assertSession($inbox);
  \PrivateSharedIngress\SharedCatalog::matches(\PrivateSharedIngress\SharedCatalog::expectedAfterCases($fragments),json_decode($inbox->query(file_get_contents($dir.'/delta.sql'))->fetchColumn(),true,64,JSON_THROW_ON_ERROR));
  self::need((int)$inbox->query('SELECT count(*) FROM portal_psp.asaas_inbox')->fetchColumn()===12);
  self::need((int)$native->query('SELECT count(*) FROM portal_onepage.entry_confirmations')->fetchColumn()===0);
  self::auditRoles($inbox);
  fwrite(STDOUT,"PASS SERVICE_RECEIPT_FINANCE_CASES_4\n");
  if($continuation!==null)$continuation($ctx,$ports,$gate,$f,$capture);
 }
 private static function auditRoles(PDO $p):void{
  $rows=$p->query("SELECT rolname,rolcanlogin,rolsuper,rolinherit,rolcreatedb,rolcreaterole,rolreplication,rolbypassrls FROM pg_roles WHERE rolname IN ('onepage_ingress_owner','onepage_ingress_capture','onepage_ingress_revoker') ORDER BY rolname")->fetchAll(PDO::FETCH_NUM);
  self::need(count($rows)===3);foreach($rows as $r){$name=array_shift($r);$r=array_map(static fn($v):bool=>in_array($v,[true,'t',1,'1'],true),$r);self::need($r===[$name!=='onepage_ingress_owner',false,false,false,false,false,false]);}
  self::need((int)$p->query("SELECT count(*) FROM pg_auth_members WHERE roleid IN (SELECT oid FROM pg_roles WHERE rolname LIKE 'onepage_ingress_%') OR member IN (SELECT oid FROM pg_roles WHERE rolname LIKE 'onepage_ingress_%')")->fetchColumn()===0);
  self::need((int)$p->query("SELECT count(*) FROM (VALUES('onepage_ingress_capture'),('onepage_ingress_revoker')) r(n) CROSS JOIN (VALUES('portal_psp.asaas_inbox'),('onepage_ingress_lab.admissions'),('onepage_ingress_lab.inbox')) t(n) WHERE has_table_privilege(r.n,t.n,'SELECT,INSERT,UPDATE,DELETE,TRUNCATE,REFERENCES,TRIGGER,MAINTAIN') OR has_any_column_privilege(r.n,t.n,'SELECT,INSERT,UPDATE,REFERENCES')")->fetchColumn()===0);
 }
}
