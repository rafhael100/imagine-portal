<?php
declare(strict_types=1);
require_once __DIR__.'/ChildSession.php';
require_once __DIR__.'/ProducerAttestation.php';
function admissionConcurrent(PDO $producer,PDO $audit,array $cluster,Closure $barrier,string $canonical,string $digest,Closure $beforeCommit): void
{
    $command=[PHP_BINARY,'-n'];
    foreach(['pdo','pdo_pgsql'] as $module){$file=ini_get('extension_dir').'/'.$module.'.so';if(is_file($file)){$command[]='-d';$command[]='extension='.$file;}}
    $command[]=__DIR__.'/concurrent-worker.php';
    $barrier();$env=['PATH'=>'/usr/bin:/bin','LC_ALL'=>'C','APP_ENV'=>'testing','ONEPAGE_PENDING_ORDER_LAB'=>'SYNTHETIC_ONLY'];
    if(getenv('LD_LIBRARY_PATH')!==false)$env['LD_LIBRARY_PATH']=getenv('LD_LIBRARY_PATH');
    $child=new AdmissionChildSession($command,$env);
    try {
        $ready=$child->receive();
        if(($ready['phase']??null)!=='READY'||!is_array($ready['row']??null))throw new RuntimeException('ADMISSION_CONCURRENCY_REFUSED');
        $q=$audit->prepare('SELECT pid,usename,datname,host(client_addr) client_addr,client_port,backend_start::text,application_name FROM pg_stat_activity WHERE pid=?');
        $q->execute([$ready['row']['pid']]);$seen=$q->fetch(PDO::FETCH_ASSOC);
        AdmissionProducerAttestation::facts($ready['row'],is_array($seen)?$seen:[],$cluster,$ready['nonce']);
        $pid=(int)$producer->query('SELECT pg_backend_pid()')->fetchColumn();
        if($pid===(int)$ready['row']['pid']||!$producer->inTransaction())throw new RuntimeException('ADMISSION_CONCURRENCY_REFUSED');
        $a=json_decode($canonical,true,32,JSON_THROW_ON_ERROR);
        $child->send(['command'=>'ADMIT','order'=>$a['order_id'],'line'=>'setup-1','canonical'=>$canonical,'digest'=>$digest]);
        // A real blocking relationship proves overlap, not timing guesses/sleeps alone.
        $q=$audit->prepare('SELECT CAST(? AS int)=ANY(pg_blocking_pids(CAST(? AS int)))');
        $end=microtime(true)+2;$blocked=false;
        do {$q->execute([$pid,$ready['row']['pid']]);$blocked=in_array($q->fetchColumn(),[true,'t',1,'1'],true);if(!$blocked)usleep(1000);}while(!$blocked&&microtime(true)<$end);
        if(!$blocked)throw new RuntimeException('ADMISSION_CONCURRENCY_NOT_OBSERVED');
        $beforeCommit();$producer->commit();
        if($child->receive(3)!==['phase'=>'RESULT','result'=>'replay'])throw new RuntimeException('ADMISSION_CONCURRENCY_REPLAY_REQUIRED');
        $barrier();
    } finally {$child->close();}
}
