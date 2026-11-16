<?php
if(getenv('CI')!=='true'||getenv('ADMIN_PHASE4_PG_CI')!=='1')exit(1);
$root=getenv('PERFORMANCE_ADMIN_LAB_ENGINE_ROOT');$tls=getenv('ADMIN_PHASE4_TLS');
foreach(['AdministrativeServiceEnvelope','AdministrativeServiceInbox','AdministrativeReadReceiver','PrivateAdministrativeService'] as $class)require $root.'/src/Domain/Administration/'.$class.'.php';
$s=json_decode(file_get_contents($tls.'/receiver.json'),true);
$db=new PDO('pgsql:host=127.0.0.1;dbname=performance_receiver_phase4_ci','phase4_receiver',$s['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
try{$result=(new Imagine\Performance\Administration\PrivateAdministrativeService($db,$tls.'/keyring.json'))->handle(stream_get_contents(STDIN,16385),getenv('PHASE4_TLS_PEER_SHA256'));echo json_encode(['status'=>200,'body'=>$result]);}
catch(Throwable){echo '{"status":403,"body":{"error":"read_denied"}}';}
