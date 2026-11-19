<?php
declare(strict_types=1);
use PrivateInboxProtocol\Audit;
function need(bool $ok):void{if(!$ok)throw new RuntimeException('OBSERVER_REFUSED');}
function receive():array{
    $s=fgets(STDIN,262146);need(is_string($s)&&strlen($s)<=262144&&str_ends_with($s,"\n")&&!str_contains($s,"\r"));
    $v=json_decode($s,true,64,JSON_THROW_ON_ERROR);need(is_array($v));return $v;
}
function send(array $v):void{
    $s=json_encode($v,JSON_THROW_ON_ERROR)."\n";need(fwrite(STDOUT,$s)===strlen($s)&&fflush(STDOUT));
}
try {
    $c=receive();
    need(array_keys($c)===['mode','nonce','postmaster','data','epoch','cluster','net','mnt','pid','port']);
    need(in_array($c['mode'],['START','POST'],true)&&preg_match('/\A[a-f0-9]{64}\z/D',$c['nonce'])===1);
    need(function_exists('posix_geteuid')&&posix_geteuid()>0&&PDO::getAvailableDrivers()===['pgsql']);
    foreach(array_keys(getenv()) as $key)need(!str_starts_with(strtoupper($key),'PG'));
    need($c['port']===55491&&is_int($c['postmaster'])&&$c['postmaster']>1&&is_int($c['epoch'])&&
        is_string($c['cluster'])&&preg_match('/\A[1-9][0-9]*\z/D',$c['cluster'])===1
        &&preg_match('~\A/tmp/native-briefing-[a-zA-Z0-9_]+/data\z~D',$c['data'])===1
        &&realpath($c['data'])===$c['data']);
    $local=static function()use($c):void{
        foreach(['net','mnt','pid'] as $ns)
            need(readlink('/proc/self/ns/'.$ns)===$c[$ns]&&readlink('/proc/'.$c['postmaster'].'/ns/'.$ns)===$c[$ns]);
        $f=$c['data'].'/postmaster.pid';
        need(is_file($f)&&!is_link($f));$p=explode("\n",file_get_contents($f));
        need($p[0]===(string)$c['postmaster']&&$p[1]===$c['data']&&$p[2]===(string)$c['epoch']&&$p[3]==='55491'
            &&$p[5]==='127.0.0.1'&&trim($p[7])==='ready');
    };
    $local();
    $db=$c['mode']==='START'?'postgres':'portal_asaas_ci';
    // Same fixture trust as #962 in its isolated topology; do not provision auth.
    $p=new PDO('pgsql:host=127.0.0.1;port=55491;dbname='.$db.';sslmode=disable;connect_timeout=3',
        'portal_native_test_bootstrap','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_PERSISTENT=>false]);
    $q=$p->query(<<<'SQL'
SELECT jsonb_build_object('backend',pg_backend_pid(),'cluster',system_identifier::text,
 'started',extract(epoch FROM pg_postmaster_start_time())::text,
 'data',current_setting('data_directory'),'port',inet_server_port(),
 'server',host(inet_server_addr()),'client',host(inet_client_addr()),'db',current_database(),
 'login',session_user,'actor',current_user,'major',current_setting('server_version_num')::int/10000,
 'listen',current_setting('listen_addresses'),'sockets',current_setting('unix_socket_directories'))::text
FROM pg_catalog.pg_control_system()
SQL);
    $identity=json_decode((string)$q->fetchColumn(),true,32,JSON_THROW_ON_ERROR);
    need($q->fetchColumn()===false&&is_array($identity)&&$identity['db']===$db&&$identity['cluster']===$c['cluster']
        &&$identity['data']===$c['data']&&$identity['port']===55491&&$identity['major']===18
        &&$identity['server']==='127.0.0.1'&&$identity['client']==='127.0.0.1'
        &&$identity['listen']==='127.0.0.1'&&$identity['sockets']===''
        &&$identity['login']==='portal_native_test_bootstrap'&&$identity['actor']===$identity['login']
        &&(int)explode('.',$identity['started'])[0]===$c['epoch']&&is_int($identity['backend'])&&$identity['backend']>1);
    $local();
    send(['phase'=>'identity','nonce'=>$c['nonce'],'identity'=>$identity]);
    $ok=receive();
    need(array_keys($ok)===['permit','nonce','backend','model']&&$ok['permit']===true&&$ok['nonce']===$c['nonce']
        &&$ok['backend']===$identity['backend']);
    $local();
    if($c['mode']==='POST'){
        foreach(['DualDbGate.php','InboxInstall.php','Compare.php','Audit.php'] as $f)require __DIR__.'/'.$f;
        $m=$ok['model'];need(is_array($m)&&array_keys($m)===['anchor','baseline','phaseAnchors','globalExpected']);
        $audit=new Audit(file_get_contents(__DIR__.'/catalog.sql'),[
           'F391'=>file_get_contents(__DIR__.'/F391.semantic.json'),'F392'=>file_get_contents(__DIR__.'/F392.semantic.json'),
        ],$m['baseline'],$m['phaseAnchors'],$m['globalExpected']);
        need(($audit->forSetupBridge())('F392',$p));
    } else {need($ok['model']===null);}
    $local();
    need($p->query('SELECT pg_backend_pid()')->fetchColumn()===$identity['backend']);
    send(['phase'=>'accepted','nonce'=>$c['nonce'],'backend'=>$identity['backend']]);
    need(fgetc(STDIN)===false);exit(0);
} catch(Throwable){exit(1);}
