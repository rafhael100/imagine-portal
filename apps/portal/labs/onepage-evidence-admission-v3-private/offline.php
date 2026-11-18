<?php
declare(strict_types=1);
require __DIR__.'/AnchorDDL.php';
require __DIR__.'/ChildSession.php';
$count=0;
$check=static function(bool $v)use(&$count):void{if(!$v)throw new RuntimeException('ADMISSION_OFFLINE_REFUSED');++$count;};
$a=['probe'=>'admit-v1:sourceEntryA','order_id'=>'11111111-1111-1111-1111-111111111111','line'=>'setup-1',
 'contract_scope'=>['id'=>'11111111-1111-1111-1111-111111111111','company'=>'22222222-2222-2222-2222-222222222222'],
 'account'=>'lab-offline','environment'=>'sandbox','customer'=>'cus_LABoffline','reference'=>'imagine:lab-op-v1:'.str_repeat('a',64),
 'currency'=>'BRL','amount_cents'=>33000,'due_date'=>'2026-11-18','payment_id'=>'pay_LABoffline'];
// This JSON is only a renderer fixture. PostgreSQL canonicalization is NOT claimed here.
$json=json_encode($a,JSON_THROW_ON_ERROR);$hash=hash('sha256',$json);$sql=AdmissionAnchorDDL::render($json,$hash);
$check(!str_contains($sql,'__ANCHOR__')&&!str_contains($sql,'__DIGEST__'));
$check(substr_count($sql,"'".$hash."'")===2);
$check(str_contains($sql,'to_jsonb(fixture_binding_witnesses)=')&&str_contains($sql,'p_evidence IS DISTINCT FROM anchor'));
$check(strpos($sql,'expected_digest CONSTANT text')<strpos($sql,'GRANT EXECUTE'));
$check(!str_contains($sql,'fixture_bind_charge('));
foreach(['line'=>'setup-2','probe'=>'admit-v1:other','payment_id'=>'pay_real','environment'=>'production','currency'=>'USD','amount_cents'=>0,
 'order_id'=>'33333333-3333-3333-3333-333333333333','customer'=>'real'] as $k=>$v){
 $b=array_replace($a,[$k=>$v]);$j=json_encode($b,JSON_THROW_ON_ERROR);$denied=false;
 try{AdmissionAnchorDDL::render($j,hash('sha256',$j));}catch(Throwable){$denied=true;}$check($denied);
}
$denied=false;try{AdmissionAnchorDDL::render($json,str_repeat('0',64));}catch(Throwable){$denied=true;}$check($denied);
$b=$a;$b['contract_scope']['note']="quote' and backslash\\ and utf8 ç";
$j=json_encode($b,JSON_THROW_ON_ERROR);$rendered=AdmissionAnchorDDL::render($j,hash('sha256',$j));
$check(str_contains($rendered,bin2hex($j))&&!str_contains($rendered,"quote'"));
$b['contract_scope']['note']='$admission_v3$';$j=json_encode($b,JSON_THROW_ON_ERROR);
$check(str_contains(AdmissionAnchorDDL::render($j,hash('sha256',$j)),bin2hex($j)));
// Execute only tiny PHP children without PDO, SQL or network.
$env=['PATH'=>'/usr/bin:/bin','LC_ALL'=>'C'];if(getenv('LD_LIBRARY_PATH')!==false)$env['LD_LIBRARY_PATH']=getenv('LD_LIBRARY_PATH');
$c=new AdmissionChildSession([PHP_BINARY,'-n','-r','echo "{\"ok\":true}\\n";'],$env);
try{$check($c->receive()===['ok'=>true]);}finally{$c->close();}
$c=new AdmissionChildSession([PHP_BINARY,'-n','-r','usleep(5000000);'],$env);
$start=microtime(true);$denied=false;try{$c->receive(.05);}catch(RuntimeException){$denied=true;}finally{$c->close();}
$check($denied&&microtime(true)-$start<2);
$c=new AdmissionChildSession([PHP_BINARY,'-n','-r','echo str_repeat("x",40000);'],$env);
$denied=false;try{$c->receive();}catch(RuntimeException){$denied=true;}finally{$c->close();}$check($denied);
echo 'PASS ADMISSION_V3_OFFLINE '.$count."\n";
