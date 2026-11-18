<?php
declare(strict_types=1);
/** Direct owned child only: bounded nonblocking pipes, TERM/KILL and reap. */
final class RecoveryChildProcess {
 public static function run(array $cmd,string $input,float $seconds=15.0):string {
  if(strlen($input)>65536||$seconds<=0||$seconds>15)throw new RuntimeException('CHILD_LIMIT');
  $p=proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
  if(!is_resource($p))throw new RuntimeException('CHILD_START');
  foreach($pipes as $f)stream_set_blocking($f,false);
  $deadline=hrtime(true)+(int)($seconds*1e9);$sent=0;$out='';$err='';$exit=null;$failed=false;
  try {
   while(true){
    if(hrtime(true)>=$deadline)throw new RuntimeException('CHILD_DEADLINE');
    $state=proc_get_status($p);if(!$state['running']&&$exit===null)$exit=$state['exitcode'];
    $r=[];foreach([1,2] as $i)if(!feof($pipes[$i]))$r[]=$pipes[$i];
    $w=isset($pipes[0])?[$pipes[0]]:[];$x=[];
    if($r||$w){
     if(@stream_select($r,$w,$x,0,20000)===false)throw new RuntimeException('CHILD_IO');
     foreach($w as $f){$n=@fwrite($f,substr($input,$sent,8192));if($n===false)throw new RuntimeException('CHILD_IO');$sent+=$n;
      if($sent===strlen($input)){fclose($pipes[0]);unset($pipes[0]);}}
     foreach($r as $f){$s=@fread($f,8192);if($s===false)throw new RuntimeException('CHILD_IO');
      if($f===$pipes[1]){$out.=$s;if(strlen($out)>65536)throw new RuntimeException('CHILD_OUTPUT');}
      else{$err.=$s;if(strlen($err)>1024)throw new RuntimeException('CHILD_OUTPUT');}}
    }else usleep(1000);
    if(!$state['running']&&feof($pipes[1])&&feof($pipes[2]))break;
   }
   if($exit!==0||$err!==''||$sent!==strlen($input))throw new RuntimeException('CHILD_REFUSED');
  }catch(Throwable){$failed=true;}
  finally{
   foreach($pipes as $f)fclose($f);
   $state=proc_get_status($p);
   if($state['running']){proc_terminate($p,15);$end=hrtime(true)+250000000;
    do{usleep(1000);$state=proc_get_status($p);}while($state['running']&&hrtime(true)<$end);
    if($state['running'])proc_terminate($p,9); // unreaped child, never a group/PID from input
   }
   proc_close($p); // external job is last resort for uninterruptible kernel I/O, not teardown proof
  }
  if($failed)throw new RuntimeException('RECOVERY_CHILD_REFUSED');return $out;
 }
}
