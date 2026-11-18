<?php
declare(strict_types=1);
require __DIR__.'/ChildProcess.php';
function childAssert(bool $v):void{if(!$v)throw new RuntimeException('CHILD_CHECK');}
try{
 $cmd=static fn(string $c)=>[PHP_BINARY,'-n','-r',$c];
 childAssert(RecoveryChildProcess::run($cmd('echo stream_get_contents(STDIN);'),'fixture',1)==='fixture');
 foreach(['usleep(2000000);','fwrite(STDOUT,str_repeat("x",70000));','fwrite(STDERR,str_repeat("x",2000));','exit(3);','echo "partial"; usleep(2000000);'] as $code){
  $start=hrtime(true);$denied=false;try{RecoveryChildProcess::run($cmd($code),'fixture',.08);}catch(RuntimeException){$denied=true;}
  childAssert($denied&&(hrtime(true)-$start)<1500000000);
 }
 if(!function_exists('pcntl_waitpid'))throw new RuntimeException('PCNTL_REQUIRED');
 $status=0;childAssert(pcntl_waitpid(-1,$status,WNOHANG)===-1);
 echo "PASS RECOVERY_CHILD_OFFLINE\n";
}catch(Throwable){fwrite(STDERR,"RECOVERY_CHILD_CHECK_REFUSED\n");exit(1);}
