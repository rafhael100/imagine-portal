<?php
declare(strict_types=1);
use PrivateD0DualDbCases\PgFixture;
use PrivateD0IntegratedLab\PdoFinancialPort;
// Called only by the post-binding hook. Missing qualification adapter is a hard stop.
return static function(array $ctx):void{
 $need=static function(bool $v):void{if(!$v)throw new RuntimeException('CASES_SETUP_REFUSED');};
 $pins=['Cases.php'=>'f41436def17437d8138aa8da8e04ae256951dd181da9814a6d58414e9af7b35b',
  'Consumer.php'=>'9ef3389444c35c016df301ec1ed37462870d62b6e2b7a0a55965f2d3b9f640a4'];
 foreach($pins as $name=>$hash)$need(hash_file('sha256',__DIR__.'/'.$name)===$hash);
 $dir=__DIR__.'/../onepage-finance-integrated';
 $sql=file_get_contents($dir.'/finance.sql');
 $need(is_string($sql)&&hash('sha256',$sql)==='da176ef9c16e5d32059456bccedbf344cfdc7dc5a6c6aad60c597feaab6e4c91');
 $provider=require __DIR__.'/qualified-ports.php';$need($provider instanceof Closure);
 // BEFORE_FINANCE must attest native config, exact admin PDO, both DBs and owned cluster.
 $before=null;
 try {
 $before=$provider('BEFORE_FINANCE',$ctx);
 $need(is_array($before)&&($before['gate']??null) instanceof Closure);
 ($ctx['recheck'])();$need(($before['gate'])('installer',$ctx['native'])===true&&!$ctx['native']->inTransaction());
 $ctx['native']->exec($sql);
 ($ctx['recheck'])();$need(($before['gate'])('finance_installed',$ctx['native'])===true);
 $ctx['native']->exec('ALTER ROLE onepage_finance_lab_verifier LOGIN');
 // Opens only through qualified Laravel configurations after attesting the reviewed new role inventory.
 // Required PDOs: inbox_a/B worker, inbox_audit/native_audit bootstrap, financial verifier.
 $qualified=$provider('AFTER_FINANCE',$ctx);
 $need(is_array($qualified)&&($qualified['gate']??null) instanceof Closure&&is_array($qualified['ports']??null));
 $gate=$qualified['gate'];$ports=$qualified['ports'];
 $need(isset($ports['financial'])&&$ports['financial'] instanceof PDO&&$gate('financial',$ports['financial'])===true);
 foreach(['Financial.php','PdoFinancialPort.php'] as $file)require_once $dir.'/'.$file;
 require_once __DIR__.'/Consumer.php';require_once __DIR__.'/Cases.php';require_once __DIR__.'/PgFixture.php';
 $pdo=$ports['financial'];
 $financial=new PdoFinancialPort($pdo,static fn(PDO $p):bool=>$p===$pdo&&$gate('financial',$p)===true,
  ['database'=>'portal_native_orders_ci','port'=>(int)getenv('PORTAL_NATIVE_ORDER_TEST_PORT')]);
 $fixture=new PgFixture($ctx,$ports,$gate,$financial);
 $result=\PrivateD0DualDbCases\cases($fixture);
 $need($result===['reap_skip_lock','claim_skip_lock','expired_ack_and_alias','lost_financial_result']);
 } finally {
  if(is_array($before)&&($before['cleanup']??null) instanceof Closure)($before['cleanup'])();
 }
};
