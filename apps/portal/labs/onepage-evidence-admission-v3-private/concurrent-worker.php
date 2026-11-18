<?php
declare(strict_types=1);
// Only launched by the already attested disposable fixture. No bootstrap credential or buyer session.
try {
    if(getenv('ONEPAGE_PENDING_ORDER_LAB')!=='SYNTHETIC_ONLY'||getenv('APP_ENV')!=='testing')throw new RuntimeException();
    $p=new PDO('pgsql:host=127.0.0.1;port=55439;dbname=portal_onepage_buyer_ci','onepage_fixture_evidence_producer','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    $nonce='admission-attest-'.bin2hex(random_bytes(16));
    $q=$p->prepare("SELECT set_config('application_name',?,false)");$q->execute([$nonce]);
    $p->exec("SET statement_timeout='5s'");
    $row=$p->query("SELECT current_database() db,current_user actor,session_user login,host(inet_server_addr()) host,
      inet_server_port() port,pg_backend_pid() pid,inet_client_port() client_port,
      extract(epoch FROM pg_postmaster_start_time())::bigint started,
      (SELECT backend_start::text FROM pg_stat_activity WHERE pid=pg_backend_pid()) backend_start,
      rolsuper OR rolbypassrls OR rolcreatedb OR rolcreaterole OR rolinherit OR rolreplication
      OR EXISTS(SELECT 1 FROM pg_auth_members WHERE member=r.oid) unsafe FROM pg_roles r WHERE rolname=current_user")->fetch(PDO::FETCH_ASSOC);
    echo json_encode(['phase'=>'READY','row'=>$row,'nonce'=>$nonce],JSON_THROW_ON_ERROR)."\n";flush();
    $input=fgets(STDIN,16385);
    if($input===false||!str_ends_with($input,"\n"))throw new RuntimeException();
    $m=json_decode($input,true,32,JSON_THROW_ON_ERROR);
    if(($m['command']??null)!=='ADMIT')throw new RuntimeException();
    $q=$p->prepare('SELECT onepage_buyer_lab.fixture_admit_entry(CAST(? AS uuid),?,CAST(? AS jsonb),?)');
    $q->execute([$m['order'],$m['line'],$m['canonical'],$m['digest']]);
    $result=$q->fetchColumn();if(!in_array($result,['recorded','replay','denied','conflict'],true))throw new RuntimeException();
    echo json_encode(['phase'=>'RESULT','result'=>$result],JSON_THROW_ON_ERROR)."\n";flush();
}catch(Throwable){fwrite(STDERR,"ADMISSION_CHILD_REFUSED\n");exit(1);}
